<?php

declare(strict_types=1);

namespace App\Domain\Settings\Enums;

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
