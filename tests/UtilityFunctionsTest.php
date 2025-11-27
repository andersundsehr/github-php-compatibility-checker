<?php

declare(strict_types=1);

namespace Andersundsehr\GithubPhpCompatibilityChecker\Tests;

use Generator;
use Andersundsehr\GithubPhpCompatibilityChecker\UtilityFunctions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UtilityFunctionsTest extends TestCase
{
    #[DataProvider('compatibilityDataProvider')]
    public function testIsCompatibleWithPhp(string $constraint, string $targetVersion, bool $expected): void
    {
        $result = UtilityFunctions::isCompatibleWithPhp($constraint, $targetVersion);
        $this->assertSame(
            $expected,
            $result,
            sprintf("Constraint '%s' with target '%s' should be ", $constraint, $targetVersion) . ($expected ? 'compatible' : 'incompatible')
        );
    }

    /**
     * Data provider for testIsCompatibleWithPhp
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function compatibilityDataProvider(): array
    {
        return [
            // Caret operator tests (^)
            'caret: ^8.1 with 8.1' => ['^8.1', '8.1', true],
            'caret: ^8.1 with 8.2' => ['^8.1', '8.2', true],
            'caret: ^8.1 with 8.3' => ['^8.1', '8.3', true],
            'caret: ^8.1 with 9.0' => ['^8.1', '9.0', false],
            'caret: ^8.1 with 7.4' => ['^8.1', '7.4', false],
            'caret: ^7.4 with 7.4' => ['^7.4', '7.4', true],
            'caret: ^7.4 with 8.0' => ['^7.4', '8.0', false],
            'caret: ^8.2 with 8.1' => ['^8.2', '8.1', false],

            // Tilde operator tests (~)
            'tilde: ~8.1 with 8.1' => ['~8.1.0', '8.1', true],
            'tilde: ~8.1 with 8.2' => ['~8.1.0', '8.2', false],
            'tilde: ~8.1 with 7.4' => ['~8.1.0', '7.4', false],
            'tilde: ~8.3 with 8.3' => ['~8.3.0', '8.3', true],
            'tilde: ~7.4 with 7.4' => ['~7.4.0', '7.4', true],
            'tilde: ~7.4 with 7.3' => ['~7.4.0', '7.3', false],

            // Greater than or equal (>=) without upper bound
            'gte: >=8.1 with 8.1' => ['>=8.1', '8.1', true],
            'gte: >=8.1 with 8.2' => ['>=8.1', '8.2', true],
            'gte: >=8.1 with 9.0' => ['>=8.1', '9.0', true],
            'gte: >=8.1 with 7.4' => ['>=8.1', '7.4', false],
            'gte: >=7.4 with 8.0' => ['>=7.4', '8.0', true],
            'gte: >=8.2 with 8.1' => ['>=8.2', '8.1', false],

            // Greater than (>)
            'gt: >8.1 with 8.2' => ['>8.1', '8.2', true],
            'gt: >8.1 with 8.1' => ['>8.1', '8.1', false],
            'gt: >8.1 with 9.0' => ['>8.1', '9.0', true],
            'gt: >8.1 with 7.4' => ['>8.1', '7.4', false],

            // Range constraints (>= and <)
            'range: >=8.1 <9.0 with 8.1' => ['>=8.1 <9.0', '8.1', true],
            'range: >=8.1 <9.0 with 8.2' => ['>=8.1 <9.0', '8.2', true],
            'range: >=8.1 <9.0 with 8.3' => ['>=8.1 <9.0', '8.3', true],
            'range: >=8.1 <9.0 with 9.0' => ['>=8.1 <9.0', '9.0', false],
            'range: >=8.1 <9.0 with 7.4' => ['>=8.1 <9.0', '7.4', false],
            'range: >=7.4 <8.0 with 7.4' => ['>=7.4 <8.0', '7.4', true],
            'range: >=7.4 <8.0 with 8.0' => ['>=7.4 <8.0', '8.0', false],

            // Range constraints (>= and <=)
            'range: >=8.1 <=8.3 with 8.1' => ['>=8.1 <=8.3', '8.1', true],
            'range: >=8.1 <=8.3 with 8.2' => ['>=8.1 <=8.3', '8.2', true],
            'range: >=8.1 <=8.3 with 8.3' => ['>=8.1 <=8.3', '8.3', true],
            'range: >=8.1 <=8.3 with 8.4' => ['>=8.1 <=8.3', '8.4', false],
            'range: >=8.1 <=8.3 with 8.0' => ['>=8.1 <=8.3', '8.0', false],

            // Exact version
            'exact: 8.1 with 8.1' => ['8.1', '8.1', true],
            'exact: 8.1 with 8.2' => ['8.1', '8.2', false],
            'exact: 7.4 with 7.4' => ['7.4', '7.4', true],
            'exact: 8 with 8.1' => ['8', '8.1', false],
            'exact: 8 with 8.2' => ['8', '8.2', false],
            'exact: 8 with 7.4' => ['8', '7.4', false],

            // OR conditions (||)
            'or: ^7.4||^8.0 with 7.4' => ['^7.4||^8.0', '7.4', true],
            'or: ^7.4||^8.0 with 8.0' => ['^7.4||^8.0', '8.0', true],
            'or: ^7.4||^8.0 with 8.1' => ['^7.4||^8.0', '8.1', true],
            'or: ^7.4||^8.0 with 9.0' => ['^7.4||^8.0', '9.0', false],
            'or: ^7.4||^8.0 with 7.3' => ['^7.4||^8.0', '7.3', false],
            'or: ^7.4.0||^8 with 7.4' => ['^7.4.0||^8', '7.4', true],
            'or: ^7.4.0||^8 with 7.5' => ['^7.4.0||^8', '7.5', true],
            'or: ^7.4.0||^8 with 8.0' => ['^7.4.0||^8', '8.0', true],
            'or: ^7.4.0||^8 with 8.1' => ['^7.4.0||^8', '8.1', true],
            'or: ^7.4.0||^8 with 8.2' => ['^7.4.0||^8', '8.2', true],
            'or: ^7.4.0||^8 with 9.0' => ['^7.4.0||^8', '9.0', false],
            'or: ^7.4.0||^8 with 7.3' => ['^7.4.0||^8', '7.3', false],
            'or: ~8.1||~8.2 with 8.1' => ['~8.1.0||~8.2.0', '8.1', true],
            'or: ~8.1||~8.2 with 8.2' => ['~8.1.0||~8.2.0', '8.2', true],
            'or: ~8.1||~8.2 with 8.3' => ['~8.1.0||~8.2.0', '8.3', false],


            // Patch versions
            'range with patch: >=8.1.0 <9.0.0 with 8.1' => ['>=8.1.0 <9.0.0', '8.1', true],
            'range with patch: >=8.1.5 <=8.2.10 with 8.2' => ['>=8.1.5 <=8.2.10', '8.2', true],
        ];
    }

    #[DataProvider('constraintTooOpenDataProvider')]
    public function testIsConstraintTooOpen(string $constraint, bool $expected): void
    {
        $majorVersion = 8;
        $result = UtilityFunctions::isConstraintTooOpen($constraint, $majorVersion);
        $this->assertSame(
            $expected,
            $result,
            sprintf("Constraint '%s' with target '%d' should be ", $constraint, $majorVersion) . ($expected ? 'too open' : 'not too open')
        );
    }

    /**
     * Data provider for testIsConstraintTooOpen
     */
    public static function constraintTooOpenDataProvider(): Generator
    {
        // Caret constraints - these allow minor version updates but not major
        yield 'caret: ^8.1' => ['^8.1', true];
        yield 'caret: ^7.4' => ['^7.4', true];
        yield 'caret: ^8' => ['^8', true];
        yield 'caret: ^8.0' => ['^8.0', true];

        // Tilde constraints - these allow patch version updates only
        yield 'tilde: ~8.1.0' => ['~8.1.0', false];
        yield 'tilde: ~8.2.0' => ['~8.2.0', false];
        yield 'tilde: ~7.4.0' => ['~7.4.0', false];

        // Greater than or equal without upper bound - TOO OPEN
        yield 'gte: >=8.1' => ['>=8.1', true];
        yield 'gte: >=7.4' => ['>=7.4', true];
        yield 'gte: >=8.0' => ['>=8.0', true];
        yield 'gte: >=5.6' => ['>=5.6', true];

        // Greater than without upper bound - TOO OPEN
        yield 'gt: >8.1' => ['>8.1', true];
        yield 'gt: >7.4' => ['>7.4', true];

        // Range constraints with upper bound - NOT too open
        yield 'range: >=8.1 <9.0' => ['>=8.1 <9.0', true];
        yield 'range: >=7.4 <8.0' => ['>=7.4 <8.0', true];
        yield 'range: >=8.0 <=8.3' => ['>=8.0 <=8.3', false];
        yield 'range: >=8.1 <10.0' => ['>=8.1 <10.0', true];

        // Exact version constraints - NOT too open
        yield 'exact: 8.1' => ['8.1', false];
        yield 'exact: 8.2' => ['8.2', false];
        yield 'exact: 7.4' => ['7.4', false];
        yield 'exact: 8.1.0' => ['8.1.0', false];

        // OR conditions - depends on whether any part is too open
        yield 'or: ^7.4||^8.0' => ['^7.4||^8.0', true];
        yield 'or: >=8.1||^7.4' => ['>=8.1||^7.4', true]; // First part is too open
        yield 'or: ^8.0||>=9.0' => ['^8.0||>=9.0', true]; // Second part is too open
        yield 'or: ~8.1.0||~8.2.0' => ['~8.1.0||~8.2.0', false];
        yield 'or: >=7.4||>=8.0' => ['>=7.4||>=8.0', true]; // Both parts are too open

        // Wildcard patterns
        yield 'wildcard: 8.*' => ['8.*', true];
        yield 'wildcard: *' => ['*', true]; // Matches any version - too open

        // Less than constraints (edge cases)
        yield 'lt: <9.0' => ['<9.0', true]; // Allows many major versions
        yield 'lte: <=8.3' => ['<=8.3', true]; // Allows many major versions
    }
}
