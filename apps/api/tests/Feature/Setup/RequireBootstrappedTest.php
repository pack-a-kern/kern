<?php

declare(strict_types=1);

namespace Tests\Feature\Setup\RequireBootstrappedTest;

use Kern\Legislation\Requirements\LegislationRequirements;
use Kern\Platform\Identity\Models\User;

/**
 * ADR 0027 step 5 — the global bootstrap gate. While the install is not bootstrapped an
 * authenticated person is held on the setup checklist; the screens that make the install ready, the
 * person's own account, and the whole JSON API stay reachable, so the gate never deadlocks the
 * state it waits for. Guests are the `auth` middleware's to redirect, not this one's.
 *
 * `is_bootstrapped` is driven through the real `ReadinessReport` by replacing the declared
 * requirements and forgetting the cached {@see LegislationRequirements} singleton, so each test
 * reconstructs it from the configuration it sets.
 *
 * `RefreshDatabase` is applied to `Feature/Setup` in `tests/Pest.php`, with the other DB-backed
 * feature suites.
 */

/** Declare one unmet required law, so the install reads as not bootstrapped. */
function notBootstrapped(): void
{
    config(['legislation.requirements.declared' => [
        'test.cod' => ['declared_by' => 'kern/test', 'tier' => 'required', 'needs' => ['law' => 'LEGE 1/2000']],
    ]]);

    app()->forgetInstance(LegislationRequirements::class);
}

/** Declare nothing required, so every required requirement is (vacuously) met. */
function bootstrapped(): void
{
    config(['legislation.requirements.declared' => []]);

    app()->forgetInstance(LegislationRequirements::class);
}

function aPerson(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

it('redirects an authenticated person to the setup checklist while the install is not bootstrapped', function (): void {
    notBootstrapped();

    $this->actingAs(aPerson(), 'web')
        ->get('/dashboard')
        ->assertRedirect(route('setup'));
});

it('lets the setup checklist itself through while not bootstrapped — never a redirect to itself', function (): void {
    notBootstrapped();

    $this->actingAs(aPerson(), 'web')
        ->get(route('setup'))
        ->assertOk();
});

it('lets the screens that make the install ready through while not bootstrapped', function (): void {
    notBootstrapped();
    $person = aPerson();

    // The minimum wage is approved on the tax-rules board; Cod Fiscal is read in on legislation.
    $this->actingAs($person, 'web')->get('/taxes/rules')->assertOk();
    $this->actingAs($person, 'web')->get('/legislation')->assertOk();
});

it('never walls the API — the requests that make the install ready still answer', function (): void {
    notBootstrapped();

    $this->actingAs(aPerson(), 'web')
        ->getJson('/api/v1/setup/requirements')
        ->assertOk()
        ->assertJson(['is_bootstrapped' => false]);
});

it('lets every screen through once the install is bootstrapped', function (): void {
    bootstrapped();

    $this->actingAs(aPerson(), 'web')
        ->get('/dashboard')
        ->assertOk();
});

it('sends a bootstrapped person off the finished setup screen to the dashboard', function (): void {
    bootstrapped();

    // Setup has nothing left on it; a bookmark to it is not a dead end.
    $this->actingAs(aPerson(), 'web')
        ->get(route('setup'))
        ->assertRedirect(route('dashboard'));
});

it('leaves a guest to the auth middleware — sent to sign in, not to setup', function (): void {
    notBootstrapped();

    $this->get('/dashboard')->assertRedirect(route('login'));
});
