<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Enums\SettingType;

/**
 * Puts the settings somebody saved on the screen in front of the defaults from `.env`.
 *
 * ## Why this had to exist
 *
 * Thirty-eight settings were writable on the screen and seven of them did anything. The other
 * thirty-one wrote a row, and the application went on reading `config()`, which is built from files and
 * environment and had never heard of the table. The screen then read the stored value back to draw
 * itself — so it displayed exactly what you had typed while the behaviour it named was unchanged. A
 * setting that is ignored is worse than one that is missing, because nothing distinguishes it from one
 * that works.
 *
 * The seven that worked did so because three services ask the repository directly. That is a fine thing
 * for a service to do and a bad thing to require: the next setting added silently does nothing, which is
 * how thirty-one of them got here.
 *
 * ## It is an overlay, not a replacement
 *
 * `.env` remains the default and the only thing that can be set before this runs. A key with no stored
 * row is left alone, so an installation that configures everything by environment behaves exactly as it
 * did. A stored row wins because somebody chose it later and more deliberately.
 *
 * ## One query, and it must not be able to break boot
 *
 * Cached, so a request costs nothing once warm, and invalidated on write rather than expiring — a person
 * who saves a setting watches for it to take effect. And wrapped: this runs during boot, before anything
 * has established that there is a reachable database with a migrated schema. `artisan migrate` on an
 * empty database would otherwise fail inside the boot of the provider that was going to create the table
 * it is querying.
 */
final readonly class StoredSettingsOverlay
{
    public const string CACHE_KEY = 'platform.settings.overlay';

    public function __construct(private ConfigRepository $config)
    {
    }

    public function apply(): void
    {
        foreach ($this->stored() as $key => $value) {
            $setting = SettingKey::tryFrom($key);

            if ($setting === null) {
                // A row for a key this version no longer has. Left where it is rather than deleted: a
                // downgrade would want it back, and nothing reads it meanwhile.
                continue;
            }

            $this->config->set($setting->configPath(), $this->cast($setting, $value));
        }
    }

    /** Forgets the cached overlay, so the next request reads the table again. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The plaintext settings, as `key => value`.
     *
     * Secrets are deliberately absent. They are stored enciphered under a key held by the KMS, and
     * unwrapping one needs a round trip that cannot be made during boot on every request — so a secret
     * stays the business of whichever service wants it, through the repository, as the AI provider's
     * already is.
     *
     * @return array<string, string>
     */
    private function stored(): array
    {
        try {
            /** @var array<string, string> $rows */
            $rows = Cache::remember(self::CACHE_KEY, now()->addDay(), static function (): array {
                /** @var array<string, string> $pairs */
                $pairs = DB::table('settings')
                    ->where('is_secret', false)
                    ->whereNotNull('value_plain')
                    ->pluck('value_plain', 'key')
                    ->all();

                return $pairs;
            });

            return $rows;
        } catch (QueryException) {
            // No database, or no `settings` table yet. An installation being built is not a misconfigured
            // one, and a missing table must never be the thing that stops the app from starting.
            return [];
        }
    }

    /**
     * The stored string as the type its config path expects.
     *
     * Settings are one text column — a form submits strings — while config consumers read integers and
     * booleans and compare them with `===`. Casting here rather than at every reader is the whole point of
     * the key knowing its own type.
     */
    /** @return string|int|float|bool|list<string>|null */
    private function cast(SettingKey $key, string $value): string|int|float|bool|array|null
    {
        if ($value === '') {
            // An emptied field means "use the default", not "use an empty string" — which for a URL or a
            // model name would be a working configuration pointing nowhere.
            return null;
        }

        return match ($key->type()) {
            SettingType::INTEGER => (int) $value,
            SettingType::DECIMAL => (float) $value,
            SettingType::BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOL),
            // Read as the list its config path holds — `banks.countries` is a list in the file too.
            SettingType::COUNTRY_LIST => SettingType::decodeCountryList($value),
            default => $value,
        };
    }
}
