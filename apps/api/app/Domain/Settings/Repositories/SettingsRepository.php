<?php

declare(strict_types=1);

namespace App\Domain\Settings\Repositories;

use App\Domain\Settings\Enums\SettingKey;
use App\Domain\Settings\Support\SystemSecretCipher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The `settings` table's only reader/writer. Plain query builder, not an Eloquent model — see
 * `App\Domain\Settings\Support\SystemSecretCipher`'s docblock for why (no UUID primary key, a
 * small closed-shape infrastructure table rather than a domain entity).
 *
 * Encrypts on write / decrypts on read transparently for whichever `SettingKey`s report
 * `isSecret() === true`; every other key is stored and returned as plain text. A caller never
 * decides encryption per call — the key's own `isSecret()` is the single source of truth, so a
 * plaintext value can never accidentally land in `value_ciphertext` or vice versa.
 */
final readonly class SettingsRepository
{
    /**
     * Matches `Kern\Audit\Support\AuditEventHasher::TIMESTAMP_FORMAT` exactly, and for the
     * identical reason: Laravel's query-builder bindings format a bound `DateTimeInterface`
     * through `Illuminate\Database\Grammar::getDateFormat()`, which is `'Y-m-d H:i:s'` — **no
     * microseconds** — regardless of the column's own precision. Passing a `CarbonImmutable`
     * object straight into `insert()`/`update()` therefore silently truncates every timestamp to
     * whole seconds no matter how the migration declared the column, making two updates within
     * the same second indistinguishable — exactly the bug `latestUpdateMeta()` exists to avoid.
     * Found empirically: the `settings` table's `updated_at`/`created_at` columns were already
     * `timestamp(6)` (`database/migrations/2026_09_09_300001_create_settings_table.php`) when
     * this was still failing, which is what pointed at the query-builder's own formatting instead
     * of the schema. Formatting explicitly to a string here, instead of handing Carbon objects to
     * the query builder, is the fix.
     */
    private const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    public function __construct(private SystemSecretCipher $cipher)
    {
    }

    public function get(SettingKey $key): ?string
    {
        $row = DB::table('settings')->where('key', $key->value)->first();

        if ($row === null) {
            return null;
        }

        if ($key->isSecret()) {
            $ciphertext = $row->value_ciphertext;

            return is_string($ciphertext) ? $this->cipher->open($key->value, $ciphertext) : null;
        }

        $plain = $row->value_plain;

        return is_string($plain) ? $plain : null;
    }

    /**
     * Upserts `$key`'s value, recording `$updatedBy` (a `users.id`, or `null` for a system-driven
     * write — nothing in this module writes without an authenticated admin today, but the
     * parameter is not hard-coded non-nullable so a future system-initiated write is not forced
     * to fabricate an actor).
     */
    public function set(SettingKey $key, string $value, ?string $updatedBy): void
    {
        $now = CarbonImmutable::now('UTC')->format(self::TIMESTAMP_FORMAT);

        $values = [
            'is_secret' => $key->isSecret(),
            'value_plain' => $key->isSecret() ? null : $value,
            'value_ciphertext' => $key->isSecret() ? $this->cipher->seal($key->value, $value) : null,
            'updated_by' => $updatedBy,
            'updated_at' => $now,
        ];

        DB::transaction(function () use ($key, $values, $now): void {
            $exists = DB::table('settings')->where('key', $key->value)->lockForUpdate()->exists();

            if ($exists) {
                DB::table('settings')->where('key', $key->value)->update($values);

                return;
            }

            DB::table('settings')->insert(['key' => $key->value, 'created_at' => $now, ...$values]);
        });
    }

    /**
     * Clears `$key`'s value without deleting the row, so `updated_at`/`updated_by` still record
     * *that* it was cleared and by whom (`DELETE /api/v1/settings/ai/api-key`). A no-op, not an
     * error, if the key was never set.
     */
    public function clear(SettingKey $key, ?string $updatedBy): void
    {
        DB::table('settings')->where('key', $key->value)->update([
            'value_plain' => null,
            'value_ciphertext' => null,
            'updated_by' => $updatedBy,
            'updated_at' => CarbonImmutable::now('UTC')->format(self::TIMESTAMP_FORMAT),
        ]);
    }

    /**
     * The most recent `updated_at`/`updated_by` across `$keys`, for a settings view that reports
     * "last changed" for a group of related keys as one value rather than five. `null`/`null` if
     * none of `$keys` has ever been written.
     *
     * @param list<SettingKey> $keys
     *
     * @return array{updatedAt: ?CarbonImmutable, updatedBy: ?string}
     */
    public function latestUpdateMeta(array $keys): array
    {
        $row = DB::table('settings')
            ->whereIn('key', array_map(static fn (SettingKey $key): string => $key->value, $keys))
            ->whereNotNull('updated_at')
            ->orderByDesc('updated_at')
            ->first(['updated_at', 'updated_by']);

        if ($row === null) {
            return ['updatedAt' => null, 'updatedBy' => null];
        }

        return [
            'updatedAt' => CarbonImmutable::parse((string) $row->updated_at, 'UTC'),
            'updatedBy' => is_string($row->updated_by) ? $row->updated_by : null,
        ];
    }
}
