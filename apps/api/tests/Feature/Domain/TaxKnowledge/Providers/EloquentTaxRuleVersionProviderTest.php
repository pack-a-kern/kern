<?php

declare(strict_types=1);

use Kern\Legislation\Enums\RuleApprovalStatus;
use Kern\Legislation\Models\TaxRule;
use Kern\Legislation\Models\TaxRuleSource;
use Kern\Legislation\Models\TaxRuleVersion;
use Kern\TaxRomania\FiscalParameters\Providers\EloquentTaxRuleVersionProvider;
use Kern\TaxRomania\TaxEngine\Exceptions\AmbiguousTaxRuleVersionException;
use Kern\TaxRomania\TaxEngine\Exceptions\NoTaxRuleVersionInForceException;
use Kern\TaxRomania\TaxEngine\Exceptions\UnknownTaxRuleException;

/**
 * Mirrors `tests/Feature/Domain/TaxKnowledge/LegislationTemporalQueryTest.php`'s shape for the
 * `tax_rule_versions` temporal lookup this module owns. Relocated from
 * `tests/Feature/Domain/TaxEngine/TaxRuleTemporalReaderTest.php` when the class it tests moved
 * from `Kern\TaxRomania\TaxEngine\Repositories\TaxRuleTemporalReader` to this adapter
 * (`docs/adr/0010-tax-engine-ports-and-adapters.md`) — same assertions, same behaviour, only the
 * class under test and its location changed.
 */
it('resolves the version in force on a given date', function (): void {
    $rule = TaxRule::factory()->create(['code' => 'RO.TEST.RESOLVE']);
    $version = TaxRuleVersion::factory()->create([
        'tax_rule_id' => $rule->id,
        'valid_from' => '2026-01-01',
        'valid_until' => '2026-12-31',
        'status' => RuleApprovalStatus::CANDIDATE,
    ]);
    TaxRuleSource::factory()->create(['rule_version_id' => $version->id]);

    $resolved = (new EloquentTaxRuleVersionProvider())->versionInForceOn('RO.TEST.RESOLVE', new DateTimeImmutable('2026-06-15'));

    expect($resolved->id)->toBe($version->id)
        ->and($resolved->ruleCode)->toBe('RO.TEST.RESOLVE')
        ->and($resolved->sourceSectionIds)->toHaveCount(1);
});

it('throws for an unknown rule code', function (): void {
    expect(fn () => (new EloquentTaxRuleVersionProvider())->versionInForceOn('RO.TEST.NOPE', new DateTimeImmutable()))
        ->toThrow(UnknownTaxRuleException::class);
});

it('throws when no version covers the date', function (): void {
    $rule = TaxRule::factory()->create(['code' => 'RO.TEST.GAP']);
    TaxRuleVersion::factory()->create([
        'tax_rule_id' => $rule->id,
        'valid_from' => '2026-01-01',
        'valid_until' => '2026-06-30',
    ]);

    expect(fn () => (new EloquentTaxRuleVersionProvider())->versionInForceOn('RO.TEST.GAP', new DateTimeImmutable('2027-01-01')))
        ->toThrow(NoTaxRuleVersionInForceException::class);
});

it('throws when two versions overlap for the same date, never picking one', function (): void {
    $rule = TaxRule::factory()->create(['code' => 'RO.TEST.OVERLAP']);
    TaxRuleVersion::factory()->create([
        'tax_rule_id' => $rule->id,
        'semantic_version' => '1.0.0',
        'valid_from' => '2026-01-01',
        'valid_until' => '2026-12-31',
    ]);
    TaxRuleVersion::factory()->create([
        'tax_rule_id' => $rule->id,
        'semantic_version' => '1.0.1',
        'valid_from' => '2026-06-01',
        'valid_until' => '2026-12-31',
    ]);

    expect(fn () => (new EloquentTaxRuleVersionProvider())->versionInForceOn('RO.TEST.OVERLAP', new DateTimeImmutable('2026-07-01')))
        ->toThrow(AmbiguousTaxRuleVersionException::class);
});

it('never resolves a REJECTED or SUPERSEDED version', function (): void {
    $rule = TaxRule::factory()->create(['code' => 'RO.TEST.REJECTED']);
    TaxRuleVersion::factory()->create([
        'tax_rule_id' => $rule->id,
        'valid_from' => '2026-01-01',
        'valid_until' => '2026-12-31',
        'status' => RuleApprovalStatus::REJECTED,
    ]);

    expect(fn () => (new EloquentTaxRuleVersionProvider())->versionInForceOn('RO.TEST.REJECTED', new DateTimeImmutable('2026-06-15')))
        ->toThrow(NoTaxRuleVersionInForceException::class);
});
