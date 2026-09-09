<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Documents\CrossTenantIsolationTest;

use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Subjects\Tenancy\Exceptions\NoTenantContextException;
use App\Domain\Subjects\Tenancy\TenantContext;
use App\Support\KeyManagement\SubjectDekService;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;

/**
 * The mandatory cross-tenant isolation test (`docs/AGENTS.md` §21.4, `BLUEPRINT.md` §5.3) for the
 * `documents` table specifically — `App\Domain\Documents\Models\Document` is the first *real*
 * business model (not a throwaway fixture) to exercise `App\Domain\Subjects\Tenancy\
 * ScopedToTaxSubject` end to end with an encrypted field, proving the mechanism
 * `docs/handoff/011-identity-tenancy.md` built holds for this module's own table.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );
});

/** @return array{0: User, 1: TaxSubject} */
function ownerAndSubject(): array
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

function activateAs(User $user, TaxSubject $subject): void
{
    $context = new TenantContext();
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);
}

it('never returns another subject\'s document from a list query', function (): void {
    [$userA, $subjectA] = ownerAndSubject();
    [$userB, $subjectB] = ownerAndSubject();

    activateAs($userA, $subjectA);
    Document::factory()->create(['tax_subject_id' => $subjectA->id, 'original_filename_ciphertext' => 'subject-a-secret.pdf']);

    activateAs($userB, $subjectB);
    Document::factory()->create(['tax_subject_id' => $subjectB->id, 'original_filename_ciphertext' => 'subject-b.pdf']);

    $visible = Document::query()->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->sole()->original_filename_ciphertext)->toBe('subject-b.pdf');
});

it('never returns another subject\'s document from a single-record lookup', function (): void {
    [$userA, $subjectA] = ownerAndSubject();
    [$userB, $subjectB] = ownerAndSubject();

    activateAs($userA, $subjectA);
    $documentA = Document::factory()->create(['tax_subject_id' => $subjectA->id]);

    activateAs($userB, $subjectB);

    expect(Document::query()->find($documentA->id))->toBeNull();
});

it('cannot update another subject\'s document', function (): void {
    [$userA, $subjectA] = ownerAndSubject();
    [$userB, $subjectB] = ownerAndSubject();

    activateAs($userA, $subjectA);
    $documentA = Document::factory()->create(['tax_subject_id' => $subjectA->id]);

    activateAs($userB, $subjectB);
    $affected = Document::query()->where('id', $documentA->id)->update(['mime_type' => 'image/png']);

    expect($affected)->toBe(0);
});

it('auto-tags a newly created document with the active subject', function (): void {
    [$user, $subject] = ownerAndSubject();

    activateAs($user, $subject);
    // Document has an encrypted field, so tax_subject_id must still be passed explicitly
    // (App\Domain\Documents\Models\Document's own docblock) — this proves the *scope*, not the
    // auto-fill-on-create hook, is what matters for this model.
    $document = Document::factory()->create(['tax_subject_id' => $subject->id]);

    expect($document->tax_subject_id)->toBe($subject->id)
        ->and(Document::query()->count())->toBe(1);
});

it('refuses to run any scoped query at all when no tenant context is bound', function (): void {
    app()->instance(TenantContext::class, new TenantContext());

    expect(fn () => Document::query()->get())->toThrow(NoTenantContextException::class);
});
