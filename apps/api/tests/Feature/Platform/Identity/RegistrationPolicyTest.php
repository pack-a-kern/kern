<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Services\RegistrationPolicy;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Repositories\SettingsRepository;

beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();
});

function closeRegistration(): void
{
    app(SettingsRepository::class)->set(SettingKey::IDENTITY_REGISTRATION_ENABLED, 'false', null);
}

function anExistingUser(): User
{
    return User::createWithEmail('resident-' . uniqid() . '@example.test', Hash::make('correct-horse-battery'));
}

it('is open while the setting says so', function (): void {
    anExistingUser();

    expect(app(RegistrationPolicy::class)->isOpen())->toBeTrue();
});

it('is closed once the setting says so and an account exists', function (): void {
    anExistingUser();
    closeRegistration();

    expect(app(RegistrationPolicy::class)->isOpen())->toBeFalse();
});

it('stays open on an instance with no users, however the setting reads', function (): void {
    // The escape hatch: there is no password reset and no console account maker, so a closed switch
    // on an empty instance would lock it permanently.
    closeRegistration();

    expect(app(RegistrationPolicy::class)->isOpen())->toBeTrue();
});

it('refuses the endpoint when registration is closed', function (): void {
    anExistingUser();
    closeRegistration();

    $this->postJson('/api/v1/auth/register', [
        'email' => 'stranger@example.test',
        'password' => 'a-sufficiently-long-password',
    ])->assertForbidden();

    expect(User::query()->where('email_blind_index', '!=', '')->count())->toBe(1);
});

it('accepts the first account even when registration is closed', function (): void {
    closeRegistration();

    $this->postJson('/api/v1/auth/register', [
        'email' => 'first-owner@example.test',
        'password' => 'a-sufficiently-long-password',
    ])->assertCreated();
});

it('accepts a registration while it is open', function (): void {
    anExistingUser();

    $this->postJson('/api/v1/auth/register', [
        'email' => 'newcomer@example.test',
        'password' => 'a-sufficiently-long-password',
    ])->assertCreated();
});
