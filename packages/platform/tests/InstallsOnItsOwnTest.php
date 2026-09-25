<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\Normalizer;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Repositories\SettingsRepository;

/**
 * The platform in an empty Laravel application: its own tables, and nothing product-shaped.
 */
it('brings its whole schema with it', function (): void {
    foreach (['users', 'user_passkeys', 'user_mfa_methods', 'user_recovery_codes', 'user_sessions',
        'encryption_key_records', 'settings', 'notifications', 'roles', 'permissions'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing {$table}");
    }

    // And the audit chain, because the platform requires it: every login and every settings change
    // records an event, so an installation with one and not the other would fail on first use.
    expect(Schema::hasTable('audit_events'))->toBeTrue()
        // The proof that nothing else is here.
        ->and(Schema::hasTable('tax_subjects'))->toBeFalse()
        ->and(Schema::hasTable('documents'))->toBeFalse();
});

it('creates a user whose email is encrypted at rest and still findable', function (): void {
    $user = User::createWithEmail('Ana.Pop@example.RO');

    $row = DB::table('users')->where('id', $user->id)->first();

    expect($row)->not->toBeNull()
        // Nothing readable is stored: not the address, not the domain.
        ->and((string) $row?->email_ciphertext)->not->toContain('example')
        ->and((string) $row?->email_ciphertext)->not->toContain('Ana')
        // And yet the lookup finds it, through the blind index rather than by decrypting rows.
        ->and(User::query()->whereEmail('ana.pop@example.ro')->first()?->id)->toBe($user->id)
        ->and($row?->email_blind_index)->toBe(app(BlindIndexHasher::class)->compute(Normalizer::email('ana.pop@example.ro')));
});

it('stores a system setting and reads it back', function (): void {
    $settings = app(SettingsRepository::class);

    expect($settings->get(SettingKey::AI_ENABLED))->toBeNull();

    $settings->set(SettingKey::AI_ENABLED, 'true', updatedBy: null);

    expect($settings->get(SettingKey::AI_ENABLED))->toBe('true');
});
