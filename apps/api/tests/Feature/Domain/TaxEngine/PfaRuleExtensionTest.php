<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Subjects\Tenancy\TenantContext;
use App\Domain\TaxEngine\Enums\TaxFactStatus;
use App\Domain\TaxEngine\Models\TaxAssessment;
use App\Domain\TaxEngine\Models\TaxFact;
use App\Domain\TaxEngine\Repositories\TaxFactRepository;
use App\Domain\TaxEngine\Services\ParameterResolver;
use App\Domain\TaxEngine\Services\RuleInvoker;
use App\Domain\TaxEngine\Services\RuleResolver;
use App\Domain\TaxEngine\Services\TaxAssessmentRecorder;
use App\Domain\TaxEngine\Services\TaxAssessor;
use App\Domain\TaxEngine\TaxContext;
use App\Domain\TaxKnowledge\Enums\FiscalParameterValueType;
use App\Domain\TaxKnowledge\Models\FiscalParameter;
use App\Domain\TaxKnowledge\Models\SourceSection;
use App\Domain\TaxKnowledge\Models\TaxRule;
use App\Domain\TaxKnowledge\Providers\EloquentFiscalParameterProvider;
use App\Domain\TaxKnowledge\Providers\EloquentTaxRuleVersionProvider;
use App\Support\Crypto\BlindIndex\BlindIndexHasher;
use App\Support\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use App\Support\KeyManagement\Contracts\KeyManagementService;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fixtures\TaxEngine\Rules\SyntheticPercentageOfBaseRule;

/**
 * Milestone 12's central `BLUEPRINT.md` §38.B claim, made executable: a rule scoped to `PFA` runs
 * through `App\Domain\TaxEngine\Services\TaxAssessor` with **zero changes to any TaxEngine
 * class** — `RuleResolver`, `ParameterResolver`, `RuleInvoker`, `TaxAssessor`,
 * `TaxAssessmentRecorder`, `TaxContext`, and `TaxFactRepository` are all byte-for-byte the same
 * classes `docs/handoff/020-tax-engine.md` shipped for PF. The only new things this test adds are
 * *data* rows (`tax_rules`/`tax_rule_versions`/`fiscal_parameters`/`fiscal_parameter_versions`)
 * tagged `subject_type = 'PFA'` and a PFA `TaxSubject` — it deliberately reuses
 * `Tests\Fixtures\TaxEngine\Rules\SyntheticPercentageOfBaseRule`, the exact same implementation
 * class M5's own `TaxAssessorTest` uses for PF, rather than adding a second near-duplicate rule
 * class (`docs/CODE-QUALITY.md` §1 — no rate/threshold/formula differs here, so a second class
 * would be pure duplication, not a genuine PFA-specific rule; see
 * `docs/reference/pf-coupling-audit.md` for the full reasoning). All numbers are clearly
 * synthetic (`RO.TEST.PFA.*`), never a real Romanian rate (`docs/AGENTS.md` §2/rule 3).
 *
 * Also proves cross-tenant isolation holds between a PF subject and a PFA subject specifically —
 * not just between two subjects of the same type, which
 * `tests/Feature/Domain/TaxEngine/CrossTenantIsolationTest.php` already covers.
 */
beforeEach(function (): void {
    app()->instance(KeyManagementService::class, new InMemoryKeyManagementService());
    $provider = new ConfigBlindIndexKeyProvider(json_encode(['1' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR), 1);
    app()->instance(BlindIndexHasher::class, new BlindIndexHasher($provider));
});

function makePfaTaxAssessor(): TaxAssessor
{
    return new TaxAssessor(
        new RuleResolver(new EloquentTaxRuleVersionProvider()),
        new ParameterResolver(new EloquentFiscalParameterProvider()),
        new RuleInvoker(),
    );
}

it('resolves and assesses a PFA-tagged synthetic rule end to end, through the unmodified engine', function (): void {
    $user = User::factory()->create();
    $pfaSubject = TaxSubject::factory()->pfa()->create();

    SubjectMembership::create([
        'subject_id' => $pfaSubject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    $rule = TaxRule::factory()->create([
        'code' => 'RO.TEST.PFA.SYNTHETIC_PERCENTAGE_OF_BASE',
        'tax_code' => 'SYNTHETIC_PFA_TAX',
        'subject_type' => 'PFA',
    ]);

    // `SyntheticPercentageOfBaseRule::evaluate()` reads a hardcoded parameter code,
    // `RO.TEST.SYNTHETIC_RATE` — reused verbatim here (this test's own `FiscalParameter` row,
    // isolated by RefreshDatabase from any other test's identically-coded row) precisely because
    // reusing the exact fixture, unmodified, is the point: nothing about "this rule now backs a
    // PFA subject" required a new rule class or a new parameter name, only a new `tax_rules` row
    // (its own code, tagged `subject_type = 'PFA'`) and a new `tax_rule_versions`/
    // `fiscal_parameter_versions` pairing with its own rate.
    $parameter = FiscalParameter::factory()->create([
        'code' => 'RO.TEST.SYNTHETIC_RATE',
        'value_type' => FiscalParameterValueType::DECIMAL,
    ]);

    insertApprovedTaxRuleVersion($rule->id, [
        'valid_from' => '2026-01-01',
        'implementation_class' => SyntheticPercentageOfBaseRule::class,
        'config_json' => json_encode(['parameter_references' => ['RO.TEST.SYNTHETIC_RATE']], JSON_THROW_ON_ERROR),
    ]);

    $section = SourceSection::factory()->create();
    insertApprovedFiscalParameterVersion($parameter->id, $section->id, [
        'valid_from' => '2026-01-01',
        'value_json' => json_encode(['value' => '0.05'], JSON_THROW_ON_ERROR),
    ]);

    $fact = TaxFact::factory()->create([
        'tax_subject_id' => $pfaSubject->id,
        'ron_amount_minor' => 200000, // 2000.00 RON of "PFA activity income", synthetic
        'status' => TaxFactStatus::CONFIRMED,
    ]);

    $context = TaxContext::forSubject($pfaSubject->id)
        ->fiscalYear(2026)
        ->asOf(new DateTimeImmutable('2026-06-01'))
        ->forRules(['RO.TEST.PFA.SYNTHETIC_PERCENTAGE_OF_BASE'])
        ->withRulesetId('RO-TEST-PFA-2026@0.0.1')
        ->withFacts((new TaxFactRepository())->snapshotsFor($pfaSubject->id, [$fact->id]))
        ->final();

    $result = makePfaTaxAssessor()->assess($context);

    expect($result->lines)->toHaveCount(1);
    $line = $result->lines[0];
    expect($line->taxCode)->toBe('SYNTHETIC_PFA_TAX')
        ->and($line->taxableBase->minorUnits)->toBe(200000)
        ->and($line->tax->minorUnits)->toBe(10000) // 5% of 2000.00 RON
        ->and($line->rate?->toString())->toBe('0.05');

    $assessment = app(TaxAssessmentRecorder::class)->record($result);

    expect($assessment->tax_subject_id)->toBe($pfaSubject->id)
        ->and($assessment->status->value)->toBe('FINAL');

    // TaxAssessment::query() is scoped by ScopedToTaxSubject as defence in depth (App\Domain\
    // TaxEngine\Repositories\TaxFactRepository already proved the explicit-filter path above) —
    // reading it back needs the same TenantContext activation any other module's caller would.
    app(TenantContext::class)->authorizeAndActivate($user->id, $pfaSubject->id);

    $persisted = TaxAssessment::query()->where('tax_subject_id', $pfaSubject->id)->first();
    expect($persisted)->not->toBeNull()
        ->and($persisted?->id)->toBe($assessment->id);
});

it('never mixes a PF subject\'s tax facts into a PFA subject\'s assessment, or vice versa', function (): void {
    $user = User::factory()->create();
    $pfSubject = TaxSubject::factory()->create(); // default factory type: PF
    $pfaSubject = TaxSubject::factory()->pfa()->create();

    foreach ([$pfSubject, $pfaSubject] as $subject) {
        SubjectMembership::create([
            'subject_id' => $subject->id,
            'user_id' => $user->id,
            'role' => MembershipRole::OWNER,
            'permissions_json' => [],
            'valid_from' => now('UTC'),
        ]);
    }

    $pfFact = TaxFact::factory()->create(['tax_subject_id' => $pfSubject->id, 'ron_amount_minor' => 100000]);
    $pfaFact = TaxFact::factory()->create(['tax_subject_id' => $pfaSubject->id, 'ron_amount_minor' => 300000]);

    // TaxFactRepository::snapshotsFor is explicitly subject-scoped — asking for the PFA fact
    // while filtering by the PF subject's id returns nothing, and vice versa.
    $repository = new TaxFactRepository();
    expect($repository->snapshotsFor($pfSubject->id, [$pfaFact->id]))->toBe([])
        ->and($repository->snapshotsFor($pfaSubject->id, [$pfFact->id]))->toBe([]);

    expect($repository->snapshotsFor($pfSubject->id, [$pfFact->id]))->toHaveCount(1)
        ->and($repository->snapshotsFor($pfaSubject->id, [$pfaFact->id]))->toHaveCount(1);

    // ScopedToTaxSubject's model-level guard (defence in depth) denies the same cross-read once a
    // TenantContext is bound to one of the two subjects.
    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $pfaSubject->id);

    $visibleIds = TaxFact::query()->pluck('id')->all();
    expect($visibleIds)->toBe([$pfaFact->id])
        ->and($visibleIds)->not->toContain($pfFact->id);
});
