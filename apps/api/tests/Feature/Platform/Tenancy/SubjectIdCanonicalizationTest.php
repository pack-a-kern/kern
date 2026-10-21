<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Tenancy\SubjectIdCanonicalizationTest;

use App\Http\Middleware\BindTaxSubjectContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Routing\ConfiguredRateLimit;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Postgres `uuid` column compares case-insensitively, so the tenant gate authorises the same
 * taxpayer however the id in the URL is cased. The raw string, though, is what the per-taxpayer rate
 * limiter keys on and what controllers pass to the encryption AAD and object keys. Re-casing the
 * UUID must therefore not mint a second rate budget or a second object key — `BindTaxSubjectContext`
 * settles the id to its one canonical, lower-case form for every subject-scoped route.
 *
 * See docs/SECURITY-FINDINGS.md, the per-taxpayer rate-limit finding (item 19) and its follow-up.
 */
uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: TaxSubject}
 */
function ownerAndSubject(): array
{
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();
    SubjectMembership::grantOwnership($subject->id, $user->id);

    return [$user, $subject];
}

it('binds the canonical lower-case id, and keys the rate limiter on it, when the URL is upper-cased', function (): void {
    [$user, $subject] = ownerAndSubject();
    $upper = strtoupper($subject->id);
    expect($upper)->not->toBe($subject->id); // a UUIDv7 has letters, so the two strings differ

    $context = app(TenantContext::class);
    $middleware = new BindTaxSubjectContext($context);

    $request = Request::create('/api/v1/subjects/' . $upper . '/wallet/overview', 'GET');
    $request->setUserResolver(fn (): User => $user);
    $route = (new Route(['GET'], 'api/v1/subjects/{subject}/wallet/overview', []))->bind($request);
    $route->setParameter('subject', $upper);
    $request->setRouteResolver(fn (): Route => $route);

    $boundId = null;
    $readBackParam = null;
    $rateKey = null;

    $middleware->handle($request, function (Request $passed) use ($context, &$boundId, &$readBackParam, &$rateKey): Response {
        $boundId = $context->currentTenantId();
        $readBackParam = $passed->route('subject');
        $rateKey = (ConfiguredRateLimit::perTenant())($passed);

        return new Response('ok');
    });

    // The bound context, the `{subject}` a controller reads back (object keys, AAD) and the
    // rate-limit key are all the one canonical lower-case id — never the upper-case the URL carried.
    expect($boundId)->toBe($subject->id)
        ->and($readBackParam)->toBe($subject->id)
        ->and($rateKey)->toBe($subject->id);
});

it('still authorises a subject route whose UUID is upper-cased', function (): void {
    [$user, $subject] = ownerAndSubject();

    // Isolation is unchanged: re-casing is the owner's own id in a different letter case, so the
    // request is served, not refused. (The point of the finding is the budget, not a leak.)
    $this->actingAs($user)
        ->getJson('/api/v1/subjects/' . strtoupper($subject->id) . '/wallet/overview')
        ->assertOk();
});

it('answers 404, not a database error, for a subject id that is not a UUID', function (): void {
    [$user] = ownerAndSubject();

    // The global `{subject}` pattern keeps a non-UUID from ever reaching the gate or a scoped query,
    // where Postgres would raise a 22P02 that surfaces as a 500.
    $this->actingAs($user)
        ->getJson('/api/v1/subjects/not-a-uuid/wallet/overview')
        ->assertNotFound();
});
