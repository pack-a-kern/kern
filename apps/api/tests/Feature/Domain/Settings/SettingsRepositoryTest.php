<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Settings\SettingsRepositoryTest;

use Illuminate\Support\Facades\DB;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Repositories\SettingsRepository;

/**
 * `updated_by` is a native Postgres `uuid` column with a real foreign key to `users.id`
 * (matching `audit_events.actor_id`'s existing convention) — every "user id" stand-in below must
 * be a real, persisted `User` row, not an arbitrary string or an unpersisted UUID. Sqlite accepts
 * either without complaint, which is exactly `docs/AGENTS.md` §7b's warned-about trap ("a `uuid`
 * column fed `'subject-1'`") — this file was fixed twice after a real Postgres run caught first
 * the type mismatch, then the missing foreign-key row.
 */
function fakeUserId(): string
{
    return User::factory()->create()->id;
}

/**
 * `Kern\Platform\Settings\Repositories\SettingsRepository` — the `settings` table's only reader/
 * writer. `AI_API_KEY` round-trips through real envelope encryption (`SystemSecretCipher`); every
 * other key is stored as plain text — a raw-database assertion proves which is which, per
 * `docs/AGENTS.md` §8/`BLUEPRINT.md` §21.4's "encrypted column raw DB assertions" requirement.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();
});

it('returns null for a key that has never been set', function (): void {
    expect(app(SettingsRepository::class)->get(SettingKey::AI_PROVIDER))->toBeNull();
});

it('round-trips a non-secret value as plain text in the database', function (): void {
    app(SettingsRepository::class)->set(SettingKey::AI_PROVIDER, 'openai', null);

    expect(app(SettingsRepository::class)->get(SettingKey::AI_PROVIDER))->toBe('openai');

    $row = DB::table('settings')->where('key', 'ai.provider')->sole();
    expect($row->is_secret)->toBeFalsy()
        ->and($row->value_plain)->toBe('openai')
        ->and($row->value_ciphertext)->toBeNull();
});

it('round-trips a secret value, storing only ciphertext in the database', function (): void {
    app(SettingsRepository::class)->set(SettingKey::AI_API_KEY, 'sk-real-secret-value', null);

    expect(app(SettingsRepository::class)->get(SettingKey::AI_API_KEY))->toBe('sk-real-secret-value');

    $row = DB::table('settings')->where('key', 'ai.api_key')->sole();
    expect($row->is_secret)->toBeTruthy()
        ->and($row->value_plain)->toBeNull()
        ->and($row->value_ciphertext)->not->toBeNull()
        ->and($row->value_ciphertext)->not->toContain('sk-real-secret-value');
});

it('records who last changed a value and when', function (): void {
    $userId = fakeUserId();
    app(SettingsRepository::class)->set(SettingKey::AI_MODEL, 'gpt-5', $userId);

    $row = DB::table('settings')->where('key', 'ai.model')->sole();
    expect($row->updated_by)->toBe($userId)
        ->and($row->updated_at)->not->toBeNull();
});

it('overwrites an existing value on a second set() rather than duplicating the row', function (): void {
    $repository = app(SettingsRepository::class);
    $repository->set(SettingKey::AI_MODEL, 'gpt-5', null);
    $repository->set(SettingKey::AI_MODEL, 'gpt-6', null);

    expect($repository->get(SettingKey::AI_MODEL))->toBe('gpt-6')
        ->and(DB::table('settings')->where('key', 'ai.model')->count())->toBe(1);
});

it('clears a secret value without deleting the row', function (): void {
    $userB = fakeUserId();
    $repository = app(SettingsRepository::class);
    $repository->set(SettingKey::AI_API_KEY, 'sk-real-secret-value', fakeUserId());
    $repository->clear(SettingKey::AI_API_KEY, $userB);

    expect($repository->get(SettingKey::AI_API_KEY))->toBeNull();

    $row = DB::table('settings')->where('key', 'ai.api_key')->sole();
    expect($row->value_ciphertext)->toBeNull()
        ->and($row->updated_by)->toBe($userB);
});

it('reports the most recently updated key among a set, or nulls if none was ever set', function (): void {
    $repository = app(SettingsRepository::class);
    $userB = fakeUserId();

    expect($repository->latestUpdateMeta([SettingKey::AI_PROVIDER, SettingKey::AI_MODEL]))
        ->toBe(['updatedAt' => null, 'updatedBy' => null]);

    $repository->set(SettingKey::AI_PROVIDER, 'openai', fakeUserId());
    usleep(2_000); // ensure a distinct timestamp for the ordering assertion below
    $repository->set(SettingKey::AI_MODEL, 'gpt-5', $userB);

    $meta = $repository->latestUpdateMeta([SettingKey::AI_PROVIDER, SettingKey::AI_MODEL]);
    expect($meta['updatedBy'])->toBe($userB);
});
