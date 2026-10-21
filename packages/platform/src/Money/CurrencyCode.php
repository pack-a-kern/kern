<?php

declare(strict_types=1);

namespace Kern\Platform\Money;

/**
 * A currency, as this product writes one: three capital letters, ISO 4217's shape.
 *
 * The check was written out nine times — in `Money`, in four request forms, in the BNR parser, the
 * statement reader and the settings — and lei appeared as `'RON'` in some twenty more places. Both
 * live here now.
 */
final class CurrencyCode
{
    /**
     * The currency this product is denominated in.
     *
     * A Romanian tax application without lei is not a configuration, it is a broken install: the
     * fiscal parameters are in RON, the assessment is in RON, and every foreign amount is converted
     * to it. So it is a constant rather than a setting.
     */
    public const string BASE = 'RON';

    private const string PATTERN = '/^[A-Z]{3}$/';

    /** Only the shape is checked, not membership of ISO 4217: BNR publishes what it publishes. */
    public static function isValid(string $code): bool
    {
        return preg_match(self::PATTERN, $code) === 1;
    }

    /**
     * The validation rules for a code in a request, to follow `required` or `sometimes`.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['string', 'size:3', 'regex:' . self::PATTERN];
    }
}
