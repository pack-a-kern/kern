<?php

declare(strict_types=1);

use Tests\Support\TaxEngineRulePurity\RulePuritySourceScanner;

/**
 * Proves the purity scanner itself works, on deliberately-constructed fixtures — the "does the
 * checker actually catch a violation" half of `docs/AGENTS.md` §11.2's "enforce with a test, not
 * just a convention." `tests/Feature/Domain/TaxEngine/RulePurityArchitectureTest.php` is the other
 * half: it runs this same scanner against every real `TaxRuleContract` implementation.
 *
 * A pure Unit test (no Laravel container/`base_path()` available here) — fixture paths are
 * resolved relative to this file instead.
 */
function taxEnginePurityFixture(string $relativePath): string
{
    return __DIR__ . '/../../../Fixtures/TaxEngine/' . $relativePath;
}

it('flags a wall-clock read via now()', function (): void {
    $violations = (new RulePuritySourceScanner())->scanFile(taxEnginePurityFixture('Purity/ImpureNowRule.php'));

    expect($violations)->not->toBe([])
        ->and(implode(' ', $violations))->toContain('now');
});

it('flags a direct database read via the DB facade', function (): void {
    $violations = (new RulePuritySourceScanner())->scanFile(taxEnginePurityFixture('Purity/ImpureDatabaseRule.php'));

    expect($violations)->not->toBe([])
        ->and(implode(' ', $violations))->toContain('DB');
});

it('flags randomness via mt_rand()', function (): void {
    $violations = (new RulePuritySourceScanner())->scanFile(taxEnginePurityFixture('Purity/ImpureRandomnessRule.php'));

    expect($violations)->not->toBe([])
        ->and(implode(' ', $violations))->toContain('mt_rand');
});

it('flags an environment read via env()', function (): void {
    $violations = (new RulePuritySourceScanner())->scanFile(taxEnginePurityFixture('Purity/ImpureEnvironmentRule.php'));

    expect($violations)->not->toBe([])
        ->and(implode(' ', $violations))->toContain('env');
});

it('flags new DateTimeImmutable() with no argument as a disguised wall-clock read', function (): void {
    $violations = (new RulePuritySourceScanner())->scanFile(taxEnginePurityFixture('Purity/ImpureNoArgumentDateTimeRule.php'));

    expect($violations)->not->toBe([])
        ->and(implode(' ', $violations))->toContain('DateTimeImmutable');
});

it('does not flag new DateTimeImmutable($explicitValue) with an argument', function (): void {
    $violations = (new RulePuritySourceScanner())->scanFile(taxEnginePurityFixture('Purity/CleanRule.php'));

    expect($violations)->toBe([]);
});

it('does not flag the real synthetic fixture rule used by TaxAssessor tests', function (): void {
    $violations = (new RulePuritySourceScanner())->scanFile(taxEnginePurityFixture('Rules/SyntheticPercentageOfBaseRule.php'));

    expect($violations)->toBe([]);
});
