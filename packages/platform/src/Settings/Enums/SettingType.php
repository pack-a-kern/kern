<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Enums;

/**
 * The value shape of a setting — drives both server-side validation and the input a settings
 * screen renders. `SECRET` is never returned to a client (`docs/handoff/041-settings-module.md`);
 * only whether one is set, and a last-four hint.
 */
enum SettingType: string
{
    case STRING = 'string';
    case INTEGER = 'integer';
    case DECIMAL = 'decimal';
    case BOOLEAN = 'boolean';
    case URL = 'url';
    case SECRET = 'secret';

    /**
     * A set of ISO 4217 codes, stored as one comma-separated string.
     *
     * Its own type rather than a `STRING` holding a comma-separated list, because the two matches in
     * `UpdateSettingsGroupController` have no `default` branch: a new shape there is a compile-time
     * demand for a rule about how it is validated and what a rejection says. A plain string would
     * have been accepted as `"lei, euro"` and read back as nothing.
     */
    case CURRENCY_LIST = 'currency_list';

    /**
     * How a boolean is stored, and how it is read back.
     *
     * Both directions live here because they were written apart and disagreed: the update endpoint
     * canonicalised `true` to the string `'1'`, while `ResolveAiProviderCredentials` tested the
     * stored value against `'true'`. So `ai.enabled` could be switched on in the settings screen,
     * saved correctly, displayed as on — and read as off by the only code that acted on it. Nothing
     * failed; the AI provider simply stayed disabled with every visible sign saying otherwise.
     *
     * A single definition on the type that owns the concept is what stops that recurring.
     */
    public const string TRUE = '1';

    public const string FALSE = '0';

    /** `true`/`false` to the canonical stored string. */
    public static function encodeBoolean(bool $value): string
    {
        return $value ? self::TRUE : self::FALSE;
    }

    /**
     * A stored string back to `true`/`false`.
     *
     * Tolerant of `'true'`/`'false'` as well as the canonical form: rows written before the two
     * halves agreed may carry either, and a setting that silently reads as off is precisely the
     * failure this method exists to end.
     */
    public static function decodeBoolean(?string $stored): ?bool
    {
        return match ($stored) {
            null => null,
            self::TRUE, 'true' => true,
            self::FALSE, 'false' => false,
            default => null,
        };
    }

    /**
     * The currency this product is denominated in, which is in every list whether it was chosen or not.
     *
     * A Romanian tax application without lei is not a configuration, it is a broken install: the
     * fiscal parameters are in RON, the assessment is in RON, and every foreign amount is converted
     * to it. So it is pinned here rather than guarded at each of the places that would otherwise
     * have to check.
     */
    public const string BASE_CURRENCY = 'RON';

    /**
     * A stored string back to the codes it names — upper-cased, deduplicated, base currency first.
     *
     * Tolerant of the spacing a person types and of an empty list, which decodes to the base
     * currency alone rather than to nothing: a select with no options is a screen that cannot be
     * used, and the one code that is always meaningful is the one the product is written in.
     *
     * @return list<string>
     */
    public static function decodeCurrencyList(?string $stored): array
    {
        $codes = [self::BASE_CURRENCY];

        foreach (explode(',', $stored ?? '') as $candidate) {
            $code = strtoupper(trim($candidate));

            if (preg_match('/^[A-Z]{3}$/', $code) === 1 && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Codes back to the stored string.
     *
     * @param  list<string>  $codes
     */
    public static function encodeCurrencyList(array $codes): string
    {
        return implode(',', self::decodeCurrencyList(implode(',', $codes)));
    }

    /**
     * A stored string back to a whole number, or null when there is no usable one.
     *
     * Null rather than zero for anything unparseable: a caller falling back to its configured
     * default is right, and a silent zero would be a ceiling of nothing.
     */
    public static function decodeInteger(?string $stored): ?int
    {
        if ($stored === null || trim($stored) === '' || ! ctype_digit(ltrim(trim($stored), '-'))) {
            return null;
        }

        return (int) trim($stored);
    }
}
