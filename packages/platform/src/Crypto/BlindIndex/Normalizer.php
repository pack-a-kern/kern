<?php

declare(strict_types=1);

namespace Kern\Platform\Crypto\BlindIndex;

use RuntimeException;
use Transliterator;

/**
 * Deterministic normalisation before hashing into a blind index (BLUEPRINT.md §4.4). Two values
 * that a human considers "the same" must normalise to identical bytes, or the index cannot find
 * a duplicate — this is the entire point of a blind index over an exact-lookup field.
 *
 * Each method is deliberately field-specific: email, CNP, and IBAN do not share a normalisation
 * rule (CNP/IBAN are digit/alphanumeric identifiers with no case or diacritic concerns; email
 * and free text do). Collapsing them into one generic "normalize(string)" would silently mis-key
 * one of them the first time a real-world value exposed the difference.
 */
final class Normalizer
{
    /**
     * Built once and kept, because building it is the expensive part.
     *
     * `Transliterator::create()` compiles an ICU rule set, which costs roughly a fifth of a
     * millisecond — nothing for one value, and the dominant cost of any operation that normalises a
     * whole table. Measured: a counterparty search over 2585 names spent 486ms here and 66ms on the
     * decryption it exists to support, because each name rebuilt this object from scratch.
     *
     * Safe to share: a transliterator holds no per-call state, and the rule set is a constant.
     */
    private static ?Transliterator $folder = null;

    private function __construct()
    {
    }

    /**
     * RFC 5321 mandates the local part is case-sensitive, but virtually no real mailbox
     * provider treats it that way, and BLUEPRINT.md §4.4's email use case is deduplication/login
     * lookup, not protocol-level delivery — so this lowercases the whole address and trims
     * surrounding whitespace. Does not attempt provider-specific tricks (Gmail dot-insensitivity,
     * `+tag` stripping): those are delivery heuristics, not identity, and inventing one here
     * would silently merge two distinct login identities.
     */
    public static function email(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * CNP is 13 digits. Strips everything but digits so formatting differences (spaces,
     * dashes some data-entry UIs insert) never produce a false "no duplicate found".
     */
    public static function cnp(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * IBAN is case-insensitive alphanumeric with no meaningful internal whitespace; banks and
     * users routinely display/enter it in groups of four separated by spaces.
     */
    public static function iban(string $value): string
    {
        $stripped = preg_replace('/\s+/', '', $value) ?? '';

        return mb_strtoupper($stripped);
    }

    /**
     * General free-text normalisation for Romanian-language fields (e.g. names, addresses) that
     * need exact-match lookup: trims, collapses internal whitespace runs to a single space,
     * lowercases, and folds diacritics (ș/ş, ț/ţ, ă, î, â — both the comma-below and
     * cedilla-below encodings of ș/ț, which real-world Romanian text mixes) down to their base
     * Latin letters via Unicode canonical decomposition. This is what stops "Ștefan" / "Stefan" /
     * "ştefan" from silently normalising to three different blind indexes for the same name.
     */
    public static function text(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        $lower = mb_strtolower($collapsed);

        return self::foldDiacritics($lower);
    }

    /**
     * Folds diacritics, or fails loudly.
     *
     * Silently returning the unfolded value here would be a security defect, not a graceful
     * degradation: the same logical value would hash to a different blind index depending on
     * whether ext-intl happened to be available, so an exact-match lookup would silently miss.
     * That would break duplicate-CNP detection and login-by-email — failures that look like
     * "no such record" rather than like an error, and so would not be noticed. A blind index
     * that is wrong is worse than one that is unavailable.
     */
    private static function foldDiacritics(string $value): string
    {
        $transliterator = self::$folder ??= Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');

        if ($transliterator === null) {
            throw new RuntimeException(
                'Blind index normalization requires a working ICU transliterator (ext-intl). '
                . 'Refusing to produce an unfolded blind index, which would not match values '
                . 'indexed on a correctly configured node.'
            );
        }

        $folded = $transliterator->transliterate($value);

        if ($folded === false) {
            throw new RuntimeException(
                'ICU transliteration failed for a blind index value. Refusing to fall back to '
                . 'the unfolded value, which would produce a non-matching blind index.'
            );
        }

        return $folded;
    }
}
