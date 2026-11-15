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
 * ADR 0027 — this package declares Cod Fiscal (present and in force) plus the active year's minimum
 * wage approved as the one baseline the whole application rests on, and resolves the `parameters`
 * need against its own models so `kern/legislation` never learns what a fiscal value is.
 */

function parametersMet(): bool
{
    return app(NeedResolvers::class)->isMet(new RequirementNeed('parameters', 'active-year'));
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

it('declares the cod-fiscal requirement with the fiscal law and active-year values', function (): void {
    $declared = config('legislation.requirements.declared');

    expect($declared)->toHaveKey('tax-romania.cod-fiscal')
        ->and($declared['tax-romania.cod-fiscal']['tier'])->toBe('required')
        ->and($declared['tax-romania.cod-fiscal']['declared_by'])->toBe('kern/tax-romania')
        ->and($declared['tax-romania.cod-fiscal']['needs']['law'])->toBe(config('fiscal_parameters.law'))
        ->and($declared['tax-romania.cod-fiscal']['needs']['parameters'])->toBe('active-year');
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
