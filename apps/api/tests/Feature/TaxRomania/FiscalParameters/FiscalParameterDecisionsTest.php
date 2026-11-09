<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\FiscalParameters\FiscalParameterDecisionsTest;

use Illuminate\Support\Facades\Artisan;
use Kern\Audit\Models\AuditEvent;
use Kern\Legislation\Models\SourceSection;
use Kern\Platform\Approval\AgentApprovalNotPermittedException;
use Kern\Platform\Approval\ApprovalStatus;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameter;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameterVersion;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deciding a fiscal value: only a person holding the permission, only on a paragraph found to say
 * it, recorded in the audit chain — and what else held for the same period set aside.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    Artisan::call('kern:access:sync');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function person(?string $role): User
{
    $user = User::factory()->create();

    if ($role !== null) {
        $user->assignRole($role);
    }

    return $user;
}

/** A proposed CASS rate for 2024, with the paragraphs found to say it. */
function proposed(?FiscalParameter $parameter = null, string $from = '2024-01-01'): FiscalParameterVersion
{
    $source = SourceSection::factory()->create(['citation_label' => 'Art. 156', 'text' => 'Cota de contribuție de asigurări sociale de sănătate este de 10%']);
    $other = SourceSection::factory()->create(['citation_label' => 'Art. 174 alin. (17)', 'text' => 'Se pot acorda bonificații de până la 10%']);

    return FiscalParameterVersion::factory()->create([
        'fiscal_parameter_id' => ($parameter ?? FiscalParameter::factory()->create(['code' => 'RO.CASS.RATE']))->id,
        'valid_from' => $from,
        'valid_until' => substr($from, 0, 4) . '-12-31',
        'value_json' => ['value' => '0.10'],
        'source_section_id' => $source->id,
        'evidence' => ['matches' => [
            ['section_id' => $source->id, 'citation' => 'Art. 156', 'document_version_id' => $source->document_version_id, 'says' => [10]],
            ['section_id' => $other->id, 'citation' => 'Art. 174 alin. (17)', 'document_version_id' => $other->document_version_id, 'says' => [10]],
        ]],
    ]);
}

it('shows the values to those who may view them, and decisions to those who may approve', function (): void {
    $version = proposed();

    $this->actingAs(person(null))->getJson('/api/v1/fiscal-parameters')->assertForbidden();
    $this->actingAs(person('ACCOUNTANT'))->getJson('/api/v1/fiscal-parameters')->assertOk()
        ->assertJsonPath('parameters.0.code', 'RO.MIN_WAGE');
    $this->actingAs(person('ACCOUNTANT'))
        ->postJson("/api/v1/fiscal-parameters/versions/{$version->id}/approve", ['section_id' => $version->source_section_id])
        ->assertForbidden();
});

it('shows the rates of bonds and shares as a tax of their own, read from income tax’s part of the law', function (): void {
    /** @var list<array{code: string, tax: string}> $rows */
    $rows = $this->actingAs(person('ACCOUNTANT'))->getJson('/api/v1/fiscal-parameters')->assertOk()->json('parameters');
    $parameters = array_column($rows, 'tax', 'code');

    expect($parameters['RO.INVESTMENTS.DIVIDEND_RATE'])->toBe('RO.INVESTMENTS')
        ->and($parameters['RO.INVESTMENTS.PUBLIC_DEBT_EXEMPT'])->toBe('RO.INVESTMENTS')
        ->and($parameters['RO.INCOME_TAX.RATE'])->toBe('RO.INCOME_TAX')
        ->and($parameters)->not->toHaveKey('RO.INVESTMENTS.INTEREST_RATE');
});

it('approves a value on a paragraph found to say it, in the name of the person, and audits it', function (): void {
    $version = proposed();
    $admin = person('SUPER_ADMIN');
    $other = $version->evidence['matches'][1]['section_id'] ?? '';

    $this->actingAs($admin)
        ->postJson("/api/v1/fiscal-parameters/versions/{$version->id}/approve", ['section_id' => SourceSection::factory()->create()->id])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'FISCAL_PARAMETER_NOT_ITS_SOURCE');

    $this->actingAs($admin)
        ->postJson("/api/v1/fiscal-parameters/versions/{$version->id}/approve", ['section_id' => $other])
        ->assertOk()
        ->assertJsonPath('status', 'APPROVED');

    $approved = $version->fresh();

    expect($approved?->status)->toBe(ApprovalStatus::APPROVED)
        ->and($approved?->approved_by)->toBe($admin->id)
        ->and($approved?->source_section_id)->toBe($other)
        ->and(AuditEvent::query()->where('event_type', 'FISCAL_PARAMETER_APPROVED')->where('actor_id', $admin->id)->exists())->toBeTrue();

    // Decided once: approving again, or rejecting, is refused with its reason.
    $this->actingAs($admin)->postJson("/api/v1/fiscal-parameters/versions/{$version->id}/reject")
        ->assertStatus(422)
        ->assertJsonPath('reason', 'FISCAL_PARAMETER_NOT_CANDIDATE');
});

it('sets aside what else held for the period, so a day has one value', function (): void {
    $first = proposed();
    $second = proposed(FiscalParameter::query()->findOrFail($first->fiscal_parameter_id));

    $this->actingAs(person('SUPER_ADMIN'))
        ->postJson("/api/v1/fiscal-parameters/versions/{$second->id}/approve", ['section_id' => $second->source_section_id])
        ->assertOk();

    expect($first->fresh()?->status)->toBe(ApprovalStatus::SUPERSEDED)
        ->and($second->fresh()?->status)->toBe(ApprovalStatus::APPROVED);
});

it('rejects a value, and records who did', function (): void {
    $version = proposed();
    $admin = person('SUPER_ADMIN');

    $this->actingAs($admin)->postJson("/api/v1/fiscal-parameters/versions/{$version->id}/reject")->assertOk();

    expect($version->fresh()?->status)->toBe(ApprovalStatus::REJECTED)
        ->and(AuditEvent::query()->where('event_type', 'FISCAL_PARAMETER_REJECTED')->where('actor_id', $admin->id)->exists())->toBeTrue();
});

it('never lets a value be saved approved any other way', function (): void {
    $version = proposed();

    $version->status = ApprovalStatus::APPROVED;

    expect(fn () => $version->save())->toThrow(AgentApprovalNotPermittedException::class);

    // A person's approval of one row lets only that row through.
    $other = proposed(FiscalParameter::query()->findOrFail($version->fiscal_parameter_id), '2025-01-01');
    $other->approveByPerson(person('SUPER_ADMIN')->id);

    expect(fn () => $version->save())->toThrow(AgentApprovalNotPermittedException::class);
});

it('drops every value read, whatever was decided, for reading again — and audits who did', function (): void {
    $rejected = proposed();
    $rejected->forceFill(['status' => ApprovalStatus::REJECTED->value])->save();
    proposed(FiscalParameter::query()->findOrFail($rejected->fiscal_parameter_id), '2025-01-01');
    $admin = person('SUPER_ADMIN');

    $this->actingAs(person('ACCOUNTANT'))->deleteJson('/api/v1/fiscal-parameters/values')->assertForbidden();

    $this->actingAs($admin)->deleteJson('/api/v1/fiscal-parameters/values')
        ->assertOk()
        ->assertJsonPath('versions_dropped', 2);

    // The parameters stay: only what was read for them goes.
    expect(FiscalParameterVersion::query()->count())->toBe(0)
        ->and(FiscalParameter::query()->where('code', 'RO.CASS.RATE')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'FISCAL_PARAMETERS_DROPPED')->where('actor_id', $admin->id)->exists())->toBeTrue();
});
