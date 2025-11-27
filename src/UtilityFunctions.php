<?php

declare(strict_types=1);

namespace Andersundsehr\GithubPhpCompatibilityChecker;

use Composer\Semver\Semver;
use Exception;

use function str_contains;

/**
 * Utility functions for GitHub PHP Compatibility Checker
 */
class UtilityFunctions
{
    private const string GITHUB_API_BASE = 'https://api.github.com';

    /**
     * Fetch the latest stable PHP version
     */
    public static function getLatestPhpVersion(): string
    {
        // Fetch all PHP versions from php.net releases API
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://www.php.net/releases/index.php?json');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PHP-Compat-Checker');
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            throw new Exception('Failed to fetch PHP versions: HTTP ' . $httpCode);
        }

        if (!$response || !is_string($response)) {
            throw new Exception('Empty response from php.net API');
        }

        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (!$data || !is_array($data)) {
            throw new Exception('Failed to parse JSON response from php.net API');
        }

        // Find the latest version by iterating through all major versions
        $latestMajor = 0;
        $latestMinor = 0;

        foreach ($data as $majorVersion => $versionData) {
            if (!is_numeric($majorVersion)) {
                continue;
            }

            $major = (int)$majorVersion;

            if (isset($versionData['version'])) {
                $parts = explode('.', (string) $versionData['version']);
                if (count($parts) >= 2) {
                    $minor = (int)$parts[1];

                    // Compare versions to find the latest
                    if ($major > $latestMajor || ($major === $latestMajor && $minor > $latestMinor)) {
                        $latestMajor = $major;
                        $latestMinor = $minor;
                    }
                }
            }
        }

        if ($latestMajor === 0) {
            throw new Exception('No valid PHP version found in php.net API response');
        }

        return $latestMajor . '.' . $latestMinor;
    }

    /**
     * Helper function to make GitHub API requests
     *
     * @param non-empty-string $url
     * @return array<string, mixed>
     */
    public static function githubApiRequest(string $url, ?string $token = null): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new Exception('Failed to initialize cURL');
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PHP-Compat-Checker');

        $headers = ['Accept: application/vnd.github+json'];
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        if ($response === false || !is_string($response)) {
            $error = curl_error($ch);
            throw new Exception('cURL error: ' . $error);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            throw new Exception(sprintf('GitHub API request failed: HTTP %s for URL: %s', $httpCode, $url));
        }

        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Fetch all repositories from an organization
     * Filters out archived repositories
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fetchOrgRepositories(string $org, ?string $token = null): array
    {
        $allRepos = [];
        $page = 1;

        do {
            $url = self::GITHUB_API_BASE . sprintf('/orgs/%s/repos?per_page=100&page=%d', $org, $page);
            $pageRepos = self::githubApiRequest($url, $token);

            $allRepos = array_merge($allRepos, $pageRepos);
            $page++;
        } while (count($pageRepos) === 100);

        // Filter out archived repositories
        $activeRepos = [];

        foreach ($allRepos as $repo) {
            if (!($repo['archived'] ?? false)) {
                $activeRepos[] = $repo;
            }
        }

        return $activeRepos;
    }

    /**
     * Fetch composer.json from a repository
     *
     * @return array<string, mixed>|null
     */
    public static function fetchComposerJson(string $owner, string $repo, ?string $token = null): ?array
    {
        try {
            $url = self::GITHUB_API_BASE . sprintf('/repos/%s/%s/contents/composer.json', $owner, $repo);
            $result = self::githubApiRequest($url, $token);

            if (!isset($result['content'])) {
                // composer.json doesn't exist - this is acceptable, return null
                return null;
            }

            $content = base64_decode((string) $result['content']);
            if (!$content) {
                throw new Exception(sprintf('Failed to decode base64 content for %s/composer.json', $repo));
            }

            $composerData = json_decode($content, true);
            if ($composerData === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(sprintf('Invalid JSON in %s/composer.json: ', $repo) . json_last_error_msg());
            }

            return $composerData;
        } catch (Exception $exception) {
            // If it's a 404, the file doesn't exist - return null
            if (str_contains($exception->getMessage(), 'HTTP 404')) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Check if a version constraint is compatible with target PHP version
     */
    public static function isCompatibleWithPhp(string $constraint, string $targetVersion): bool
    {
        if ($targetVersion === '' || !preg_match('/^\d+(\.\d+){0,2}$/', $targetVersion)) {
            throw new Exception('Invalid target version format: ' . $targetVersion);
        }

        return Semver::satisfies($targetVersion, $constraint);
    }

    /**
     * Check if a version constraint is too open (allows multiple minor versions)
     */
    public static function isConstraintTooOpen(string $constraint, int $upperBound = 99): bool
    {
        foreach (range(5, $upperBound + 1) as $major) {
            $versionToTest = $major . '.999.999';
            if (Semver::satisfies($versionToTest, $constraint)) {
                return true;
            }
        }

        return false;
    }
}
