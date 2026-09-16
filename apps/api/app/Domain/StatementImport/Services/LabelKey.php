<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Services;

use Normalizer;

/**
 * One spelling for a word that a bank writes several ways.
 *
 * `Tranzactie la`, `Tranzacţie la` and `TRANZACTIE LA` are one label; `septembrie` and `Septembrie`
 * are one month. Both comparisons need the same treatment — lower case, diacritics removed — and
 * having it in one place is what stops a form built against one spelling silently reading nothing
 * from a file that uses the other.
 */
final readonly class LabelKey
{
    public static function for(string $value): string
    {
        $folded = mb_strtolower(trim($value));
        $decomposed = Normalizer::normalize($folded, Normalizer::FORM_D);

        if (! is_string($decomposed)) {
            return $folded;
        }

        // The combining marks left behind by decomposition — the accent, not the letter.
        return preg_replace('/\p{Mn}/u', '', $decomposed) ?? $folded;
    }
}
