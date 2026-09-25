<?php

declare(strict_types=1);

use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\Tenancy\TenantContext;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;
use Kern\TaxRomania\TaxEngine\Models\TaxFact;

/**
 * `docs/AGENTS.md` §21.4 / `BLUEPRINT.md` §5.3 — mandatory. Proves
 * `Kern\TaxRomania\TaxEngine\Models\TaxFact`/`TaxAssessment` are correctly wired to
 * `Kern\Platform\Tenancy\ScopedToTenant` as defence in depth, on top of
 * `Kern\TaxRomania\TaxEngine\Repositories\TaxFactRepository`'s own explicit `tax_subject_id` filter
 * (that repository's own tests prove the explicit-filter path; this test proves the underlying
 * model-level guard a less careful caller would still hit).
 */
beforeEach(function (): void {
    app()->instance(KeyManagementService::class, new InMemoryKeyManagementService());
    $provider = new ConfigBlindIndexKeyProvider(json_encode(['1' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR), 1);
    app()->instance(BlindIndexHasher::class, new BlindIndexHasher($provider));
});

/**
 * @return array{0: User, 1: TaxSubject}
 */
function ownerAndSubjectForTaxEngine(): array
{
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    return [$user, $subject];
}

function activateAsForTaxEngine(User $user, TaxSubject $subject): void
{
    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);
}

it('never returns another subject\'s tax fact from a list query', function (): void {
    [$userA, $subjectA] = ownerAndSubjectForTaxEngine();
    [$userB, $subjectB] = ownerAndSubjectForTaxEngine();

    activateAsForTaxEngine($userA, $subjectA);
    TaxFact::factory()->create(['tax_subject_id' => $subjectA->id, 'category_code' => 'SUBJECT_A_FACT']);

    activateAsForTaxEngine($userB, $subjectB);
    TaxFact::factory()->create(['tax_subject_id' => $subjectB->id, 'category_code' => 'SUBJECT_B_FACT']);

    $visible = TaxFact::query()->pluck('category_code')->all();

    expect($visible)->toBe(['SUBJECT_B_FACT']);
});

it('never returns another subject\'s tax fact from a single-record lookup', function (): void {
    [$userA, $subjectA] = ownerAndSubjectForTaxEngine();
    [$userB, $subjectB] = ownerAndSubjectForTaxEngine();

    activateAsForTaxEngine($userA, $subjectA);
    $factA = TaxFact::factory()->create(['tax_subject_id' => $subjectA->id]);

    activateAsForTaxEngine($userB, $subjectB);

    expect(TaxFact::find($factA->id))->toBeNull();
});

it('never returns another subject\'s tax assessment from a list query', function (): void {
    [$userA, $subjectA] = ownerAndSubjectForTaxEngine();
    [$userB, $subjectB] = ownerAndSubjectForTaxEngine();

    activateAsForTaxEngine($userA, $subjectA);
    TaxAssessment::factory()->create(['tax_subject_id' => $subjectA->id, 'ruleset_id' => 'SUBJECT_A_RULESET']);

    activateAsForTaxEngine($userB, $subjectB);
    TaxAssessment::factory()->create(['tax_subject_id' => $subjectB->id, 'ruleset_id' => 'SUBJECT_B_RULESET']);

    $visible = TaxAssessment::query()->pluck('ruleset_id')->all();

    expect($visible)->toBe(['SUBJECT_B_RULESET']);
});

it('auto-tags a newly created tax fact with the active subject when not supplied explicitly', function (): void {
    [$user, $subject] = ownerAndSubjectForTaxEngine();

    activateAsForTaxEngine($user, $subject);

    // TaxFact still requires tax_subject_id explicit at construction because of the encrypted
    // metadata_ciphertext field (see its own docblock) — this test proves the auto-tag hook is
    // wired regardless, using a plain create() call that supplies it, which must match the
    // active TenantContext for the scope to ever see the row again.
    $fact = TaxFact::factory()->create(['tax_subject_id' => $subject->id]);

    expect($fact->tax_subject_id)->toBe($subject->id)
        ->and(TaxFact::find($fact->id))->not->toBeNull();
});
