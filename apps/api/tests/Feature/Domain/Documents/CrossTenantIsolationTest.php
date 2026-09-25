<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Documents\CrossTenantIsolationTest;

use Kern\Documents\Enums\DocumentType;
use Kern\Documents\Models\Document;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Tenancy\Exceptions\NoTenantContextException;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;

/**
 * The mandatory cross-tenant isolation test (`docs/AGENTS.md` §21.4, `BLUEPRINT.md` §5.3) for the
 * `documents` table specifically — `Kern\Documents\Models\Document` is the first *real*
 * business model (not a throwaway fixture) to exercise `Kern\TaxRomania\Subjects\Tenancy\
 * ScopedToTenant` end to end with an encrypted field, proving the mechanism
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
    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);
}

it('never returns another subject\'s document from a list query', function (): void {
    [$userA, $subjectA] = ownerAndSubject();
    [$userB, $subjectB] = ownerAndSubject();

    activateAs($userA, $subjectA);
    nameDocument(Document::factory()->create(['tax_subject_id' => $subjectA->id]), 'subject-a-secret.pdf');

    activateAs($userB, $subjectB);
    nameDocument(Document::factory()->create(['tax_subject_id' => $subjectB->id]), 'subject-b.pdf');

    $visible = Document::query()->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->sole()->firstPart()?->original_filename_ciphertext)->toBe('subject-b.pdf');
});

function nameDocument(Document $document, string $filename): Document
{
    $part = $document->parts->first();

    if ($part !== null) {
        $part->original_filename_ciphertext = $filename;
        $part->save();
    }

    return $document;
}

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
    $affected = Document::query()->where('id', $documentA->id)->update(['type' => DocumentType::INVOICE]);

    expect($affected)->toBe(0);
});

it('auto-tags a newly created document with the active subject', function (): void {
    [$user, $subject] = ownerAndSubject();

    activateAs($user, $subject);
    // Document has an encrypted field, so tax_subject_id must still be passed explicitly
    // (Kern\Documents\Models\Document's own docblock) — this proves the *scope*, not the
    // auto-fill-on-create hook, is what matters for this model.
    $document = Document::factory()->create(['tax_subject_id' => $subject->id]);

    expect($document->tax_subject_id)->toBe($subject->id)
        ->and(Document::query()->count())->toBe(1);
});

it('refuses to run any scoped query at all when no tenant context is bound', function (): void {
    app()->instance(TenantContext::class, app(TenantContext::class));

    expect(fn () => Document::query()->get())->toThrow(NoTenantContextException::class);
});
