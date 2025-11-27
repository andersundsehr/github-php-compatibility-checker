<?php

/**
 * GitHub Organization PHP Compatibility Checker
 * Identifies repositories that are not compatible with the newest PHP version
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Andersundsehr\GithubPhpCompatibilityChecker\UtilityFunctions;

// Configuration
const DEFAULT_ORG = 'andersundsehr';

// Process form submission
$results = null;
$error = null;
$org = DEFAULT_ORG;
$token = null;
$targetPhpVersion = null;
$showForks = false; // Default value

// Handle token clearing
if (isset($_GET['clear_token'])) {
    setcookie('github_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Retrieve token from cookie if it exists
$savedToken = $_COOKIE['github_token'] ?? '';

try {
    $targetPhpVersion = UtilityFunctions::getLatestPhpVersion();
} catch (\Exception $exception) {
    $error = "Failed to fetch latest PHP version: " . $exception->getMessage();
}

// Handle POST form submission - redirect to GET
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $org = trim($_POST['org'] ?? DEFAULT_ORG);
    $token = trim($_POST['token'] ?? '') ?: null;
    $showForks = isset($_POST['show_forks']);

    // Save token to cookie if provided (expires in 30 days)
    if ($token) {
        setcookie('github_token', $token, [
            'expires' => time() + (30 * 24 * 60 * 60),
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }

    // Build redirect URL with GET parameters
    $params = ['org' => $org];
    if ($showForks) {
        $params['show_forks'] = '1';
    }
    $redirectUrl = '?' . http_build_query($params);
    header('Location: ' . $redirectUrl);
    exit;
}

// Handle GET request - perform the check
if (isset($_GET['org']) && !$error) {
    $org = trim((string) $_GET['org']);
    $showForks = isset($_GET['show_forks']);
    $token = $savedToken ?: null;

    if ($org === '' || $org === '0') {
        $error = 'Organization name is required';
    } else {
        try {
            $repos = UtilityFunctions::fetchOrgRepositories($org, $token);

            $results = [];
            foreach ($repos as $repo) {
                // Skip forks if show_forks is not checked
                if (!$showForks && ($repo['fork'] ?? false)) {
                    continue;
                }

                try {
                    $composerJson = UtilityFunctions::fetchComposerJson($org, $repo['name'], $token);

                    $phpRequirement = null;
                    $compatible = null;
                    $status = 'No composer.json';
                    $isTooOpen = false;

                    if ($composerJson && isset($composerJson['require']['php'])) {
                        $phpRequirement = $composerJson['require']['php'];

                        try {
                            $compatible = UtilityFunctions::isCompatibleWithPhp($phpRequirement, $targetPhpVersion);
                            $status = $compatible ? 'Compatible' : 'Incompatible';

                            // Check if constraint is too open
                            $isTooOpen = UtilityFunctions::isConstraintTooOpen($phpRequirement, (int)explode('.', $targetPhpVersion)[0]);
                        } catch (\Exception $e) {
                            // If we can't parse the version constraint, mark as error
                            $status = 'Parse Error';
                            $phpRequirement .= ' (Error: ' . $e->getMessage() . ')';
                            $isTooOpen = false;
                        }
                    } elseif ($composerJson) {
                        // composer.json exists but no PHP requirement - treat as "*" (compatible with all versions)
                        $phpRequirement = 'N/A';
                        $compatible = false;
                        $status = 'N/A';
                        $isTooOpen = true;
                    }

                    $results[] = [
                        'name' => $repo['name'],
                        'url' => $repo['html_url'],
                        'php_requirement' => $phpRequirement ?? 'N/A',
                        'compatible' => $compatible,
                        'status' => $status,
                        'is_fork' => $repo['fork'] ?? false,
                        'last_commit' => $repo['pushed_at'] ?? null,
                        'too_open' => $isTooOpen,
                    ];
                } catch (\Exception $e) {
                    // If there's an error fetching this specific repo, add it to results with error status
                    $results[] = [
                        'name' => $repo['name'],
                        'url' => $repo['html_url'],
                        'php_requirement' => 'Error: ' . $e->getMessage(),
                        'compatible' => null,
                        'status' => 'Error',
                        'is_fork' => $repo['fork'] ?? false,
                        'last_commit' => $repo['pushed_at'] ?? null,
                        'too_open' => false,
                    ];
                }
            }

            // Sort: incompatible first, then no composer.json, then compatible
            // Within each category, sort by last commit (most recent first)
            usort($results, function (array $a, array $b): int {
                // First, sort by compatibility status
                if ($a['compatible'] === false && $b['compatible'] !== false) {
                    return -1;
                }
                if ($a['compatible'] !== false && $b['compatible'] === false) {
                    return 1;
                }
                if ($a['compatible'] === null && $b['compatible'] === true) {
                    return -1;
                }
                if ($a['compatible'] === true && $b['compatible'] === null) {
                    return 1;
                }

                // Within the same compatibility category, sort by last commit date (most recent first)
                $aTime = $a['last_commit'] ? strtotime((string) $a['last_commit']) : 0;
                $bTime = $b['last_commit'] ? strtotime((string) $b['last_commit']) : 0;

                if ($aTime === $bTime) {
                    return strcasecmp((string) $a['name'], (string) $b['name']);
                }

                return $bTime <=> $aTime; // Descending order (most recent first)
            });
        } catch (\Exception $e) {
            $error = "Failed to fetch repositories: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GitHub PHP Compatibility Checker</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%23667eea'/><text x='50' y='70' font-size='60' text-anchor='middle' fill='white'>🔍</text></svg>">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        h1 {
            font-size: 2em;
            margin-bottom: 10px;
        }

        .subtitle {
            opacity: 0.9;
            font-size: 1.1em;
        }

        .form-section {
            padding: 30px;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #dee2e6;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
        }

        .form-row {
            display: grid;
            grid-template-columns: 2fr 3fr;
            gap: 20px;
        }

        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            margin: 20px 30px;
            border-radius: 6px;
            border-left: 4px solid #f5c6cb;
        }

        .results-section {
            padding: 30px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }

        .stat-card.incompatible {
            background: #ffe0e0;
            color: #c92a2a;
        }

        .stat-card.compatible {
            background: #d3f9d8;
            color: #2b8a3e;
        }

        .stat-card.no-composer {
            background: #fff3cd;
            color: #856404;
        }

        .stat-card.too-open {
            background: #fff4e6;
            color: #e67700;
        }

        .stat-card.needs-attention {
            background: #fff0f0;
            color: #d63031;
        }

        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9em;
            opacity: 0.8;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        thead {
            background: #f8f9fa;
        }

        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }

        .status-badge.incompatible {
            background: #ffe0e0;
            color: #c92a2a;
        }

        .status-badge.compatible {
            background: #d3f9d8;
            color: #2b8a3e;
        }

        .status-badge.no-composer {
            background: #e9ecef;
            color: #495057;
        }

        .status-badge.error {
            background: #fff3cd;
            color: #856404;
        }

        .repo-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }

        .repo-link:hover {
            text-decoration: underline;
        }

        .fork-badge {
            display: inline-block;
            background: #e7f5ff;
            color: #1864ab;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.75em;
            font-weight: 600;
            margin-left: 8px;
            border: 1px solid #a5d8ff;
        }

        .warning-badge {
            display: inline-block;
            background: #fff3cd;
            color: #856404;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.75em;
            font-weight: 600;
            margin-left: 8px;
            border: 1px solid #ffc107;
        }

        .filter-buttons {
            margin-bottom: 20px;
        }

        .filter-btn {
            background: #e9ecef;
            color: #495057;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            margin-right: 10px;
            cursor: pointer;
            font-size: 14px;
        }

        .filter-btn.active {
            background: #667eea;
            color: white;
        }

        .help-text {
            font-size: 0.9em;
            color: #6c757d;
            margin-top: 5px;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 0.9em;
            }

            th, td {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🔍 GitHub PHP Compatibility Checker</h1>
            <p class="subtitle">Identify repositories incompatible with PHP <?= htmlspecialchars((string) $targetPhpVersion) ?></p>
        </header>

        <div class="form-section">
            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label for="org">GitHub Organization:</label>
                        <input type="text" id="org" name="org" value="<?= htmlspecialchars($org) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="token">GitHub Token (Optional):</label>
                        <input type="password" id="token" name="token" placeholder="<?= $savedToken ? '••••••••••••••••' : 'ghp_...' ?>" value="">
                        <p class="help-text">Optional: Increases API rate limit from 60 to 5000 requests/hour.
                            <a href="https://github.com/settings/tokens/new?description=PHP%20Compatibility%20Checker&scopes=public_repo" target="_blank" style="color: #667eea; text-decoration: underline;">Create a token here</a>
                            <?php if ($savedToken) : ?>
                                | <a href="?clear_token=1" style="color: #c92a2a; text-decoration: underline;">Clear saved token</a>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="form-group">
                    <label style="display: flex; align-items: center; cursor: pointer;">
                        <input type="checkbox" id="showForks" name="show_forks" value="1" <?= $showForks ? 'checked' : '' ?> style="margin-right: 8px; width: auto; cursor: pointer;">
                        <span>Show forked repositories</span>
                    </label>
                </div>
                <button type="submit">Check Repositories</button>
            </form>
        </div>

        <?php if ($error) : ?>
            <div class="error">
                <strong>Error:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($results !== null) : ?>
            <div class="results-section">
                <?php
                $incompatibleCount = count(array_filter($results, fn(array $r): bool => $r['compatible'] === false));
                $compatibleCount = count(array_filter($results, fn(array $r): bool => $r['compatible'] === true));
                $noComposerCount = count(array_filter($results, fn(array $r): bool => $r['compatible'] === null));
                $tooOpenCount = count(array_filter($results, fn(array $r): bool => $r['too_open']));
                $needsAttentionCount = count(array_filter($results, fn(array $r): bool => ($r['compatible'] === false) || $r['too_open']));
                ?>

                <div class="stats">
                    <div class="stat-card needs-attention">
                        <div class="stat-number"><?= $needsAttentionCount ?></div>
                        <div class="stat-label">Needs Attention</div>
                    </div>
                    <div class="stat-card incompatible">
                        <div class="stat-number"><?= $incompatibleCount ?></div>
                        <div class="stat-label">Incompatible</div>
                    </div>
                    <div class="stat-card too-open">
                        <div class="stat-number"><?= $tooOpenCount ?></div>
                        <div class="stat-label">Too Open</div>
                    </div>
                    <div class="stat-card compatible">
                        <div class="stat-number"><?= $compatibleCount ?></div>
                        <div class="stat-label">Compatible</div>
                    </div>
                    <div class="stat-card no-composer">
                        <div class="stat-number"><?= $noComposerCount ?></div>
                        <div class="stat-label">No composer.json</div>
                    </div>
                </div>

                <div class="info-message" style="background: #e7f5ff; color: #1864ab; padding: 12px 20px; margin: 20px 0; border-radius: 6px; border-left: 4px solid #1864ab;">
                    <strong>ℹ️ Note:</strong> Archived repositories are not shown in this list.
                </div>

                <div class="filter-buttons">
                    <button class="filter-btn" onclick="filterTable('all')">All (<?= count($results) ?>)</button>
                    <button class="filter-btn active" onclick="filterTable('needs-attention')">Needs Attention (<?= $needsAttentionCount ?>)</button>
                    <button class="filter-btn" onclick="filterTable('incompatible')">Incompatible (<?= $incompatibleCount ?>)</button>
                    <button class="filter-btn" onclick="filterTable('too-open')">Too Open (<?= $tooOpenCount ?>)</button>
                    <button class="filter-btn" onclick="filterTable('compatible')">Compatible (<?= $compatibleCount ?>)</button>
                    <button class="filter-btn" onclick="filterTable('no-composer')">No Composer (<?= $noComposerCount ?>)</button>
                </div>

                <table id="resultsTable">
                    <thead>
                        <tr>
                            <th>Repository</th>
                            <th>PHP Requirement</th>
                            <th>Last Commit</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result) : ?>
                            <?php
                                $rowClass = 'no-composer';
                                $badgeClass = 'no-composer';
                            if ($result['compatible'] === false) {
                                $rowClass = 'incompatible';
                                $badgeClass = 'incompatible';
                            } elseif ($result['compatible'] === true) {
                                $rowClass = 'compatible';
                                $badgeClass = 'compatible';
                            } elseif ($result['status'] === 'Error' || $result['status'] === 'Parse Error') {
                                $rowClass = 'error';
                                $badgeClass = 'error';
                            }

                                // Build additional row classes for filtering
                                $rowClasses = ['row-' . $rowClass];
                            if ($result['too_open']) {
                                $rowClasses[] = 'row-too-open';
                            }
                            if (($result['compatible'] === false) || $result['too_open']) {
                                $rowClasses[] = 'row-needs-attention';
                            }
                            ?>
                            <tr class="<?= implode(' ', $rowClasses) ?>">
                                <td>
                                    <a href="<?= htmlspecialchars((string) $result['url']) ?>" target="_blank" class="repo-link">
                                        <?= htmlspecialchars((string) $result['name']) ?>
                                    </a>
                                    <?php if ($result['is_fork']) : ?>
                                        <span class="fork-badge">🍴 Fork</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string) $result['php_requirement']) ?>
                                    <?php if ($result['too_open']) : ?>
                                        <span class="warning-badge" title="This constraint allows multiple minor versions. Consider using ~ instead of ^ to limit to bugfix versions only.">⚠️ Too Open</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($result['last_commit']) : ?>
                                        <?php
                                            $timestamp = strtotime((string) $result['last_commit']);
                                            $timeAgo = time() - $timestamp;
                                            $days = floor($timeAgo / 86400);

                                        if ($days < 1) {
                                            echo 'Today';
                                        } elseif ($days < 30) {
                                            echo $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
                                        } elseif ($days < 365) {
                                            $months = floor($days / 30);
                                            echo $months . ' month' . ($months > 1 ? 's' : '') . ' ago';
                                        } else {
                                            $years = floor($days / 365);
                                            echo $years . ' year' . ($years > 1 ? 's' : '') . ' ago';
                                        }
                                        ?>
                                    <?php else : ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge <?= $badgeClass ?>">
                                        <?= htmlspecialchars($result['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function filterTable(filter) {
            const rows = document.querySelectorAll('#resultsTable tbody tr');
            const buttons = document.querySelectorAll('.filter-btn');

            // Update active button
            buttons.forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');

            // Filter rows
            rows.forEach(row => {
                if (filter === 'all') {
                    row.style.display = '';
                } else {
                    row.style.display = row.classList.contains('row-' + filter) ? '' : 'none';
                }
            });
        }

        // Apply needs-attention filter on page load
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('#resultsTable tbody tr');
            rows.forEach(row => {
                if (!row.classList.contains('row-needs-attention')) {
                    row.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>
