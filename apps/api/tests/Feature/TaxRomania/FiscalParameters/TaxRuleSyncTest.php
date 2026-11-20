<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Approval\ApprovalStatus;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\TaxRules\TaxRuleVersion;
use Spatie\Permission\PermissionRegistrar;

/**
 * The screen's "rules in sync?" panel and its Import button — the same {@see \Kern\TaxRomania\TaxEngine\Registration\TaxRuleRegistrar}
 * the CLI uses, reached over HTTP. Reading the figures needs `fiscal.parameters.view`; importing needs
 * `fiscal.parameters.approve`, so an accountant can see the state without being able to change what the
 * installation computes.
 */
beforeEach(function (): void {
    Artisan::call('kern:access:sync');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function ruleSyncUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('reports the rule classes defined in code against those registered', function (): void {
    $response = $this->actingAs(ruleSyncUser('SUPER_ADMIN'))
        ->getJson('/api/v1/fiscal-parameters/rules/sync')
        ->assertOk();

    expect($response->json('definedInCode'))->toBeGreaterThan(0)
        ->and($response->json('registered'))->toBe(0)
        ->and($response->json('inSync'))->toBeFalse()
        ->and($response->json('missing'))->not->toBeEmpty();
});

it('imports the missing rules as candidates, and never approves one', function (): void {
    $actor = ruleSyncUser('SUPER_ADMIN');

    $response = $this->actingAs($actor)
        ->postJson('/api/v1/fiscal-parameters/rules/import')
        ->assertOk();

    $defined = $response->json('definedInCode');

    expect($response->json('recorded'))->toBe($defined)
        ->and($response->json('inSync'))->toBeTrue()
        ->and($response->json('registered'))->toBe($defined);

    $versions = TaxRuleVersion::query()->withoutGlobalScopes()->get();

    expect($versions)->not->toBeEmpty()
        ->and($versions->every(fn (TaxRuleVersion $v): bool => $v->status === ApprovalStatus::CANDIDATE))->toBeTrue();

    // Running it again adds nothing — the table is already level with the code, and an existing version
    // is never repointed or duplicated.
    $this->actingAs($actor)
        ->postJson('/api/v1/fiscal-parameters/rules/import')
        ->assertOk()
        ->assertJson(['recorded' => 0, 'inSync' => true]);
});

it('lets someone who may read the board see the sync, but not import', function (): void {
    $accountant = ruleSyncUser('ACCOUNTANT');

    $this->actingAs($accountant)->getJson('/api/v1/fiscal-parameters/rules/sync')->assertOk();
    $this->actingAs($accountant)->postJson('/api/v1/fiscal-parameters/rules/import')->assertForbidden();

    expect(TaxRuleVersion::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses the sync to a user with no platform role', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/fiscal-parameters/rules/sync')
        ->assertForbidden();
});
