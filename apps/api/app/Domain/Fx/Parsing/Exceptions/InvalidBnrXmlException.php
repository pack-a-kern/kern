<?php

declare(strict_types=1);

namespace App\Domain\Fx\Parsing\Exceptions;

use RuntimeException;

/**
 * Thrown for any structural or content problem in an XML document
 * {@see \App\Domain\Fx\Parsing\BnrXmlParser} was asked to parse as a BNR feed — malformed XML, no
 * `<Cube date="...">` elements, an invalid date, an invalid currency code, an invalid multiplier,
 * or a non-numeric rate value. Never constructed with more than the offending token itself —
 * public rate data, so no PII concern, but there is no reason to echo an entire (potentially
 * large) document body into an exception message either.
 */
final class InvalidBnrXmlException extends RuntimeException
{
    public static function malformed(string $reason): self
    {
        return new self(sprintf('Malformed BNR XML: %s', $reason));
    }

    public static function noCubeElements(): self
    {
        return new self('BNR XML document contains no <Cube date="..."> elements.');
    }

    public static function invalidDate(string $dateAttribute): self
    {
        return new self(sprintf('Invalid Cube date "%s" — expected YYYY-MM-DD.', $dateAttribute));
    }

    public static function duplicateDate(string $dateAttribute): self
    {
        return new self(sprintf('Duplicate Cube date "%s" in the same document.', $dateAttribute));
    }

    public static function noRatesForDate(string $dateAttribute): self
    {
        return new self(sprintf('Cube date "%s" contains no <Rate> elements.', $dateAttribute));
    }

    public static function invalidCurrencyCode(string $currency, string $dateAttribute): self
    {
        return new self(sprintf('Invalid currency code "%s" on date "%s" — expected 3 uppercase letters.', $currency, $dateAttribute));
    }

    public static function duplicateCurrency(string $currency, string $dateAttribute): self
    {
        return new self(sprintf('Duplicate currency "%s" on date "%s".', $currency, $dateAttribute));
    }

    public static function invalidMultiplier(string $currency, string $multiplierAttribute, string $dateAttribute): self
    {
        return new self(sprintf(
            'Invalid multiplier "%s" for currency "%s" on date "%s" — expected a positive integer.',
            $multiplierAttribute,
            $currency,
            $dateAttribute,
        ));
    }

    public static function invalidRateValue(string $currency, string $dateAttribute): self
    {
        return new self(sprintf('Invalid (non-numeric) rate value for currency "%s" on date "%s".', $currency, $dateAttribute));
    }
}
