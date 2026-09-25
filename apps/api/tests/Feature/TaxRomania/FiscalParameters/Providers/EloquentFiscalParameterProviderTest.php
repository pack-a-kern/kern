<?php

declare(strict_types=1);

use Kern\Platform\Approval\ApprovalStatus;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameter;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameterVersion;
use Kern\TaxRomania\FiscalParameters\Providers\EloquentFiscalParameterProvider;
use Kern\TaxRomania\TaxEngine\Exceptions\AmbiguousFiscalParameterVersionException;
use Kern\TaxRomania\TaxEngine\Exceptions\NoFiscalParameterVersionInForceException;
use Kern\TaxRomania\TaxEngine\Exceptions\UnknownFiscalParameterException;

/**
 * Relocated from `tests/Feature/TaxRomania/TaxEngine/FiscalParameterTemporalReaderTest.php` when the
 * class it tests moved from `Kern\TaxRomania\TaxEngine\Repositories\FiscalParameterTemporalReader` to
 * this adapter (`docs/adr/0010-tax-engine-ports-and-adapters.md`) — same assertions, same
 * behaviour, only the class under test and its location changed.
 */
it('resolves the parameter version in force on a given date', function (): void {
    $parameter = FiscalParameter::factory()->create(['code' => 'RO.TEST.RESOLVE_PARAM']);
    $version = FiscalParameterVersion::factory()->create([
        'fiscal_parameter_id' => $parameter->id,
        'valid_from' => '2026-01-01',
        'valid_until' => '2026-12-31',
        'value_json' => ['value' => '0.10'],
        'status' => ApprovalStatus::CANDIDATE,
    ]);

    $resolved = (new EloquentFiscalParameterProvider())->versionInForceOn('RO.TEST.RESOLVE_PARAM', new DateTimeImmutable('2026-06-15'));

    expect($resolved->parameterVersionId)->toBe($version->id)
        ->and($resolved->valueJson)->toBe(['value' => '0.10']);
});

it('throws for an unknown parameter code', function (): void {
    expect(fn () => (new EloquentFiscalParameterProvider())->versionInForceOn('RO.TEST.NOPE', new DateTimeImmutable()))
        ->toThrow(UnknownFiscalParameterException::class);
});

it('throws when no version covers the date', function (): void {
    $parameter = FiscalParameter::factory()->create(['code' => 'RO.TEST.GAP_PARAM']);
    FiscalParameterVersion::factory()->create([
        'fiscal_parameter_id' => $parameter->id,
        'valid_from' => '2026-01-01',
        'valid_until' => '2026-06-30',
    ]);

    expect(fn () => (new EloquentFiscalParameterProvider())->versionInForceOn('RO.TEST.GAP_PARAM', new DateTimeImmutable('2027-01-01')))
        ->toThrow(NoFiscalParameterVersionInForceException::class);
});

it('throws when two versions overlap for the same date', function (): void {
    $parameter = FiscalParameter::factory()->create(['code' => 'RO.TEST.OVERLAP_PARAM']);
    FiscalParameterVersion::factory()->create([
        'fiscal_parameter_id' => $parameter->id,
        'valid_from' => '2026-01-01',
        'valid_until' => '2026-12-31',
    ]);
    FiscalParameterVersion::factory()->create([
        'fiscal_parameter_id' => $parameter->id,
        'valid_from' => '2026-06-01',
        'valid_until' => '2026-12-31',
    ]);

    expect(fn () => (new EloquentFiscalParameterProvider())->versionInForceOn('RO.TEST.OVERLAP_PARAM', new DateTimeImmutable('2026-07-01')))
        ->toThrow(AmbiguousFiscalParameterVersionException::class);
});

it('answers with the approved value where a candidate was proposed beside it', function (): void {
    bootstrapIdentityCryptoForTests();
    $parameter = FiscalParameter::factory()->create(['code' => 'RO.TEST.APPROVED_FIRST']);
    $approved = FiscalParameterVersion::factory()->create([
        'fiscal_parameter_id' => $parameter->id,
        'valid_from' => '2026-01-01',
        'valid_until' => '2026-12-31',
        'value_json' => ['value' => '0.10'],
    ]);
    $approved->approveByPerson(User::factory()->create()->id);
    FiscalParameterVersion::factory()->create([
        'fiscal_parameter_id' => $parameter->id,
        'valid_from' => '2026-01-01',
        'valid_until' => '2026-12-31',
        'value_json' => ['value' => '0.12'],
    ]);

    $resolved = (new EloquentFiscalParameterProvider())->versionInForceOn('RO.TEST.APPROVED_FIRST', new DateTimeImmutable('2026-06-15'));

    expect($resolved->parameterVersionId)->toBe($approved->id);
});
