<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Tests\Integration\CodFiscalRequirementTest;

use Kern\Legislation\Requirements\NeedResolvers;
use Kern\Legislation\Requirements\RequirementNeed;
use Kern\Platform\Approval\ApprovalStatus;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Database\Factories\FiscalParameterFactory;
use Kern\TaxRomania\Database\Factories\FiscalParameterVersionFactory;
use Kern\TaxRomania\FiscalParameters\Requirements\FiscalParameterReadiness;
use Kern\TaxRomania\TaxEngine\Services\FiscalYears;

/**
 * ADR 0027 — this package declares the one baseline the whole application rests on as two required
 * requirements: Cod Fiscal present and in force, and the active year's minimum wage (its decision read
 * by this package's watch, its value approved). It resolves the `parameters` need against its own
 * models so `kern/legislation` never learns what a fiscal value is.
 */

function parametersMet(): bool
{
    return app(NeedResolvers::class)->isMet(new RequirementNeed('parameters', 'active-year'));
}

/** The resolved status of the active-year `parameters` need — met, plus the detail the screen reads. */
function parametersStatus(): \Kern\Legislation\Requirements\NeedStatus
{
    return app(NeedResolvers::class)->status(new RequirementNeed('parameters', 'active-year'));
}

/**
 * The minimum wage with a version for the active year. A version starts a candidate; an approved one
 * is reached only through the sanctioned path that names the person who decided it
 * ({@see \Kern\Platform\Approval\ForbidsAgentApproval}) — no agent sets `APPROVED` (AGENTS §9).
 */
function wageVersion(ApprovalStatus $status): void
{
    $wage = FiscalParameterFactory::new()->create(['code' => config('fiscal_parameters.minimum_wage.parameter')]);
    $version = FiscalParameterVersionFactory::new()->create([
        'fiscal_parameter_id' => $wage->id,
        'valid_from' => app(FiscalYears::class)->current() . '-01-01',
        'valid_until' => null,
        'status' => ApprovalStatus::CANDIDATE,
    ]);

    if ($status === ApprovalStatus::APPROVED) {
        $version->approveByPerson(User::factory()->create()->id);
    }
}

it('declares the cod-fiscal requirement with the fiscal law as its one need', function (): void {
    $declared = config('legislation.requirements.declared');

    expect($declared)->toHaveKey('tax-romania.cod-fiscal')
        ->and($declared['tax-romania.cod-fiscal']['tier'])->toBe('required')
        ->and($declared['tax-romania.cod-fiscal']['declared_by'])->toBe('kern/tax-romania')
        ->and($declared['tax-romania.cod-fiscal']['needs'])->toBe(['law' => config('fiscal_parameters.law')]);
});

it('declares the minimum-wage requirement needing its watch read and the value approved', function (): void {
    $declared = config('legislation.requirements.declared');

    // The watch argument is the key this package declares the minimum-wage watch under in watches.php,
    // so the need and the watch that satisfies it name the same thing.
    expect($declared)->toHaveKey('tax-romania.minimum-wage')
        ->and($declared['tax-romania.minimum-wage']['tier'])->toBe('required')
        ->and($declared['tax-romania.minimum-wage']['declared_by'])->toBe('kern/tax-romania')
        ->and($declared['tax-romania.minimum-wage']['needs']['watch'])->toBe('tax-romania.minimum_wage')
        ->and($declared['tax-romania.minimum-wage']['needs']['parameters'])->toBe('active-year')
        ->and(config('legislation.watches.declared'))->toHaveKey('tax-romania.minimum_wage');
});

it('registers the parameters resolver on the shared registry', function (): void {
    expect(app(NeedResolvers::class)->knows('parameters'))->toBeTrue();
});

it('is unmet while no minimum wage is approved for the active year', function (): void {
    expect(parametersMet())->toBeFalse()
        ->and(app(FiscalParameterReadiness::class)->minimumWageApprovedForActiveYear())->toBeFalse();
});

it('a candidate wage does not count — only an approved one', function (): void {
    wageVersion(ApprovalStatus::CANDIDATE);

    expect(parametersMet())->toBeFalse();
});

it('is met once the minimum wage is approved for the active year', function (): void {
    wageVersion(ApprovalStatus::APPROVED);

    expect(parametersMet())->toBeTrue()
        ->and(app(FiscalParameterReadiness::class)->minimumWageApprovedForActiveYear())->toBeTrue();
});

it('rejects a parameters need declared with any argument but active-year', function (): void {
    expect(fn (): bool => app(NeedResolvers::class)->isMet(new RequirementNeed('parameters', 'next-year')))
        ->toThrow(\RuntimeException::class, 'active-year');
});

it('reports the active year as waiting while nothing is proposed', function (): void {
    $status = parametersStatus();

    expect($status->met)->toBeFalse()
        ->and($status->detail)->toBe(['state' => 'absent', 'year' => app(FiscalYears::class)->current()]);
});

it('names a proposed value awaiting approval, with its year', function (): void {
    wageVersion(ApprovalStatus::CANDIDATE);

    $status = parametersStatus();

    expect($status->met)->toBeFalse()
        ->and($status->detail['state'])->toBe('proposed')
        ->and($status->detail['year'])->toBe(app(FiscalYears::class)->current())
        ->and($status->detail)->toHaveKey('display')
        ->and($status->detail['display'])->toBeArray();
});

it('reports approved once the active year is approved', function (): void {
    wageVersion(ApprovalStatus::APPROVED);

    $status = parametersStatus();

    expect($status->met)->toBeTrue()
        ->and($status->detail)->toBe(['state' => 'approved']);
});
