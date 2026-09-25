<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Declarations\CrossTenantIsolationTest;

use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Declarations\Models\Declaration;
use Kern\TaxRomania\Declarations\Models\FormVersion;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;

/**
 * `docs/AGENTS.md` §21.4 / `BLUEPRINT.md` §5.3 — a user authorized for one `TaxSubject` must never
 * reach another subject's `declarations` row. `Kern\TaxRomania\Declarations\Models\
 * DeclarationSubmission` is deliberately not exercised here: it carries no `tax_subject_id`
 * column at all (matching `BLUEPRINT.md` §6.10's own literal schema, and the identical precedent
 * `Kern\TaxRomania\TaxEngine\Models\TaxAssessmentLine` already sets for a child row "reached only
 * through its owning parent, tenancy enforced one level up") — a direct, unauthenticated query
 * against that table is not a scenario `ScopedToTenant` can guard, by design; every sanctioned
 * read path (`$declaration->submissions()`) goes through an already-tenant-scoped `Declaration`
 * first.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

/**
 * @return array{0: User, 1: TaxSubject}
 */
function declarationsOwnerAndSubject(): array
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

function activateDeclarationsSubject(User $user, TaxSubject $subject): void
{
    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);
}

function declarationForSubject(string $subjectId): Declaration
{
    $assessment = TaxAssessment::factory()->final()->create([
        'tax_subject_id' => $subjectId,
        'fiscal_year' => 2026,
    ]);
    $formVersion = FormVersion::factory()->create();

    return Declaration::create([
        'tax_subject_id' => $subjectId,
        'form_version_id' => $formVersion->id,
        'fiscal_year' => 2026,
        'assessment_id' => $assessment->id,
    ]);
}

it("never lets one subject see another subject's declarations row", function (): void {
    [$userA, $subjectA] = declarationsOwnerAndSubject();
    [$userB, $subjectB] = declarationsOwnerAndSubject();

    activateDeclarationsSubject($userA, $subjectA);
    $declarationA = declarationForSubject($subjectA->id);

    activateDeclarationsSubject($userB, $subjectB);
    declarationForSubject($subjectB->id);

    $visible = Declaration::query()->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()?->tax_subject_id)->toBe($subjectB->id);

    expect(Declaration::find($declarationA->id))->toBeNull();
});
