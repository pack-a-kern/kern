<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kern\Platform\Identity\Models\User;

uses(RefreshDatabase::class);

/*
 * Horizon's own dashboard is shut to everyone, in every environment (App\Providers\AppServiceProvider).
 *
 * Its default lets everyone in when APP_ENV is `local`, signed out included, and compose.dev.yaml
 * makes `local` the value whenever `.env` has none. Each case runs as `local` for that reason: it is
 * the one environment where the default would have answered yes.
 */
beforeEach(function (): void {
    app()->detectEnvironment(static fn (): string => 'local');
});

it('refuses the dashboard and its job lists to everyone, even in a local environment', function (?Closure $who, string $path): void {
    $user = $who === null ? null : $who();

    ($user instanceof User ? $this->actingAs($user) : $this)
        ->get($path)
        ->assertForbidden();
})->with([
    'signed out' => [null],
    'a signed-in user' => [static fn (): User => ordinaryUser()],
    'an administrator' => [static fn (): User => settingsAdminUser()],
])->with([
    'the dashboard' => ['/horizon'],
    'its completed jobs' => ['/horizon/api/jobs/completed'],
    'its failed jobs' => ['/horizon/api/jobs/failed'],
]);
