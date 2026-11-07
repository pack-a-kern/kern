<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Enums;

use Kern\Platform\Money\CurrencyCode;
use Locale;

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
     * A set of ISO 3166-1 alpha-2 codes, stored as one comma-separated string and read as a list.
     *
     * Its own type for the reason `CURRENCY_LIST` is: `"Romania"` must be refused, not saved and read
     * back as a country nobody has.
     */
    case COUNTRY_LIST = 'country_list';

    /** One of the values the key lists in `SettingKey::choices()` — a mode, not free text. */
    case CHOICE = 'choice';

    /**
     * Text this installation sends in a request header, such as a User-Agent.
     *
     * Printable ASCII on one line and no longer than {@see self::MAX_HEADER_VALUE_LENGTH}: a line break
     * in a header is a second header the sender never meant, and a byte outside ASCII is one a server is
     * free to refuse.
     */
    case HEADER_VALUE = 'header_value';

    /** How a source may reach this installation's operator: an e-mail address or an https URL, header-safe. */
    case CONTACT = 'contact';

    public const int MAX_HEADER_VALUE_LENGTH = 255;

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
        $codes = [CurrencyCode::BASE];

        foreach (explode(',', $stored ?? '') as $candidate) {
            $code = strtoupper(trim($candidate));

            if (CurrencyCode::isValid($code) && ! in_array($code, $codes, true)) {
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
     * A stored string back to the country codes it names — upper-cased, deduplicated, in order.
     *
     * @return list<string>
     */
    public static function decodeCountryList(?string $stored): array
    {
        $codes = [];

        foreach (explode(',', $stored ?? '') as $candidate) {
            $code = strtoupper(trim($candidate));

            if (self::isCountryCode($code) && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * A country ISO 3166-1 assigns, as ICU knows them: `RO`, not `ZZ`, which ICU keeps for
     * "unknown region", or `XX`, which it does not know at all.
     */
    public static function isCountryCode(string $code): bool
    {
        return preg_match('/\A[A-Z]{2}\z/', $code) === 1
            && $code !== 'ZZ'
            && Locale::getDisplayRegion('-' . $code, 'en') !== $code;
    }

    /** Whether `$value` can be sent in a header as written: printable ASCII, one line, not blank. */
    public static function isHeaderValue(string $value): bool
    {
        return trim($value) !== '' && preg_match('/\A[\x20-\x7E]{1,' . self::MAX_HEADER_VALUE_LENGTH . '}\z/', $value) === 1;
    }

    /** Whether `$value` is a contact a source can use — an e-mail address or an https URL — and header-safe. */
    public static function isContact(string $value): bool
    {
        if (! self::isHeaderValue($value)) {
            return false;
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false && parse_url($value, PHP_URL_SCHEME) === 'https';
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
