<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Services;

/**
 * What is safe to send about a statement line.
 *
 * ## Why anything is sent at all
 *
 * Matching columns needs no values — headers and shapes are enough. Saying what "KAUFLAND 1234" is
 * does need the name, because the name is the question. So the second AI call sends counterparty
 * names and a short description, and this is what it strips out of them first.
 *
 * ## What goes
 *
 * - **Any run of six or more digits.** A CNP is thirteen, an IBAN's body is twenty-odd, a card
 *   number sixteen, an account reference anything — and none of them helps a model decide whether a
 *   payment is groceries or rent. Six is low enough to catch them and high enough to keep a year, a
 *   postcode or an invoice number like "2026".
 * - **Anything IBAN-shaped**, whatever the spacing: two letters, two digits, then at least eleven
 *   more characters is not a name and is not needed to classify one.
 *
 * A CNP is never sent to an external provider by this application at all (`docs/AGENTS.md`); this is
 * the belt for a statement line that happens to print one in its description.
 */
final readonly class MaskedStatementText
{
    /** Runs this long or longer are replaced. */
    private const int DIGIT_RUN = 6;

    private const string PLACEHOLDER = '###';

    public static function mask(string $text): string
    {
        // IBANs first: masking their digits alone would leave "RO## ####" behind, which is still a
        // recognisable fragment of one.
        $masked = preg_replace(
            '/\b[A-Z]{2}\d{2}[\s]?(?:[A-Z0-9][\s]?){11,30}\b/i',
            self::PLACEHOLDER,
            $text,
        ) ?? $text;

        $masked = preg_replace('/\d{' . self::DIGIT_RUN . ',}/', self::PLACEHOLDER, $masked) ?? $masked;

        // Digits split by spaces or dashes, as a card or an account number is often printed.
        $masked = preg_replace('/(?:\d[\s-]?){' . self::DIGIT_RUN . ',}/', self::PLACEHOLDER, $masked) ?? $masked;

        return trim(preg_replace('/\s{2,}/u', ' ', $masked) ?? $masked);
    }
}
