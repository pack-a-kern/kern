<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Settings;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kern\Platform\Money\CurrencyCode;
use Kern\Platform\Settings\Enums\SettingGroup;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Enums\SettingType;
use Kern\Platform\Settings\Repositories\SettingsRepository;
use Kern\Platform\Settings\Services\ResolveSettings;

/**
 * Updates one group of settings.
 *
 * Per group rather than all at once so a rejected value in one section cannot block an unrelated
 * change in another.
 *
 * **Read-only keys are refused here, by the server.** The screen also disables them, but a disabled
 * input is a hint a caller can ignore; this is the control. Each refused key would silently corrupt
 * existing data rather than fail loudly — see `SettingKey::isReadOnly()`.
 *
 * An explicit `null` clears an override, restoring the inherited value; an absent key is left
 * untouched. Those are different intents and are not conflated.
 */
final readonly class UpdateSettingsGroupController
{
    public function __construct(
        private SettingsRepository $settings,
        private ResolveSettings $resolver,
    ) {
    }

    public function __invoke(Request $request, string $group): JsonResponse
    {
        $settingGroup = SettingGroup::tryFrom($group);

        if ($settingGroup === null) {
            return new JsonResponse(['message' => 'Unknown settings group.'], 404);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->validate([
            'settings' => ['required', 'array'],
        ])['settings'];

        $allowed = [];
        foreach ($settingGroup->keys() as $key) {
            $allowed[$key->value] = $key;
        }

        $actorId = $request->user()?->getAuthIdentifier();
        // Two passes, deliberately: validate the whole group first, apply only if all of it is
        // valid. Applying as we go would leave a partly-updated group behind on the first bad
        // value — harder to reason about, and harder to undo, than a rejection.
        $errors = [];

        /** @var list<array{key: SettingKey, value: ?string}> $pending */
        $pending = [];

        foreach ($payload as $rawKey => $rawValue) {
            $key = $allowed[$rawKey] ?? null;

            if ($key === null) {
                $errors["settings.{$rawKey}"] = ['This setting does not belong to this group.'];

                continue;
            }

            if ($key->isReadOnly()) {
                $errors["settings.{$rawKey}"] = ['This setting cannot be changed once data exists.'];

                continue;
            }

            if ($key->isSetOnlyWithItsPair()) {
                $errors["settings.{$rawKey}"] = ['The provider address and key are changed together, in the connection dialog.'];

                continue;
            }

            if ($rawValue === null) {
                $pending[] = ['key' => $key, 'value' => null];

                continue;
            }

            $normalized = $this->normalize($key, $rawValue);

            if ($normalized === null) {
                $errors["settings.{$rawKey}"] = [$this->expectation($key)];

                continue;
            }

            $pending[] = ['key' => $key, 'value' => $normalized];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $applied = [];

        foreach ($pending as $change) {
            $actor = is_string($actorId) ? $actorId : null;

            if ($change['value'] === null) {
                $this->settings->clear($change['key'], $actor);
            } else {
                $this->settings->set($change['key'], $change['value'], $actor);
            }

            $applied[] = $change['key']->value;
        }

        return new JsonResponse([
            'applied' => $applied,
            'settings' => array_map(
                static fn ($setting): array => $setting->toArray(),
                $this->resolver->forGroup($settingGroup),
            ),
        ]);
    }

    private function normalize(SettingKey $key, mixed $value): ?string
    {
        return match ($key->type()) {
            // Encoded through the type, so the writer and every reader share one definition of
            // what a stored boolean looks like.
            SettingType::BOOLEAN => match (true) {
                is_bool($value) => SettingType::encodeBoolean($value),
                $value === SettingType::TRUE || $value === SettingType::FALSE => (string) $value,
                default => null,
            },
            SettingType::INTEGER => is_int($value) || (is_string($value) && ctype_digit($value))
                ? (string) (int) $value
                : null,
            SettingType::DECIMAL => is_numeric($value) ? (string) $value : null,
            SettingType::URL => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false
                ? $value
                : null,
            SettingType::STRING, SettingType::SECRET => is_string($value) && trim($value) !== ''
                ? $value
                : null,
            SettingType::CURRENCY_LIST => $this->currencyList($value),
            SettingType::COUNTRY_LIST => $this->countryList($value),
        };
    }

    /**
     * The codes a list holds, upper-cased — or null if any member is not one `$valid` accepts.
     *
     * Rejected rather than filtered: somebody who typed `lei` wants to be told, not to have it
     * quietly dropped and the save reported as successful. Accepts the array the screen sends and
     * the comma-separated string an API caller would.
     *
     * @param  Closure(string): bool  $valid
     * @return list<string>|null
     */
    private function codes(mixed $value, Closure $valid): ?array
    {
        $codes = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : null);

        if ($codes === null) {
            return null;
        }

        $clean = [];

        foreach ($codes as $candidate) {
            if (! is_string($candidate)) {
                return null;
            }

            $code = strtoupper(trim($candidate));

            if ($code === '') {
                continue;
            }

            if (! $valid($code)) {
                return null;
            }

            $clean[] = $code;
        }

        return $clean;
    }

    private function currencyList(mixed $value): ?string
    {
        $codes = $this->codes($value, CurrencyCode::isValid(...));

        return $codes === null ? null : SettingType::encodeCurrencyList($codes);
    }

    /**
     * None is refused rather than saved: a bank list for no country is not a setting anyone means,
     * and clearing the field (`null`) is how the configured default comes back.
     */
    private function countryList(mixed $value): ?string
    {
        $codes = $this->codes($value, SettingType::isCountryCode(...));

        return $codes === null || $codes === [] ? null : implode(',', SettingType::decodeCountryList(implode(',', $codes)));
    }

    private function expectation(SettingKey $key): string
    {
        return match ($key->type()) {
            SettingType::BOOLEAN => 'Expected true or false.',
            SettingType::INTEGER => 'Expected a whole number.',
            SettingType::DECIMAL => 'Expected a number.',
            SettingType::URL => 'Expected a valid URL.',
            SettingType::STRING, SettingType::SECRET => 'Expected a non-empty value.',
            SettingType::CURRENCY_LIST => 'Expected three-letter currency codes.',
            SettingType::COUNTRY_LIST => 'Expected two-letter country codes, such as RO.',
        };
    }
}
