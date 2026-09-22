<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\TaxKnowledge\FiscalParameters\FiscalParameterDecisionsTest;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\User;
use App\Domain\TaxKnowledge\Enums\RuleApprovalStatus;
use App\Domain\TaxKnowledge\Exceptions\AgentApprovalNotPermittedException;
use App\Domain\TaxKnowledge\Models\FiscalParameter;
use App\Domain\TaxKnowledge\Models\FiscalParameterVersion;
use App\Domain\TaxKnowledge\Models\SourceSection;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deciding a fiscal value: only a person holding the permission, only on a paragraph found to say
 * it, recorded in the audit chain — and what else held for the same period set aside.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
    Artisan::call('taxos:access:sync');
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

    expect($approved?->status)->toBe(RuleApprovalStatus::APPROVED)
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

    expect($first->fresh()?->status)->toBe(RuleApprovalStatus::SUPERSEDED)
        ->and($second->fresh()?->status)->toBe(RuleApprovalStatus::APPROVED);
});

it('rejects a value, and records who did', function (): void {
    $version = proposed();
    $admin = person('SUPER_ADMIN');

    $this->actingAs($admin)->postJson("/api/v1/fiscal-parameters/versions/{$version->id}/reject")->assertOk();

    expect($version->fresh()?->status)->toBe(RuleApprovalStatus::REJECTED)
        ->and(AuditEvent::query()->where('event_type', 'FISCAL_PARAMETER_REJECTED')->where('actor_id', $admin->id)->exists())->toBeTrue();
});

it('never lets a value be saved approved any other way', function (): void {
    $version = proposed();

    $version->status = RuleApprovalStatus::APPROVED;

    expect(fn () => $version->save())->toThrow(AgentApprovalNotPermittedException::class);

    // A person's approval of one row lets only that row through.
    $other = proposed(FiscalParameter::query()->findOrFail($version->fiscal_parameter_id), '2025-01-01');
    $other->approveByPerson(person('SUPER_ADMIN')->id);

    expect(fn () => $version->save())->toThrow(AgentApprovalNotPermittedException::class);
});
