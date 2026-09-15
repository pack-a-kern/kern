<?php

declare(strict_types=1);

namespace App\Support\Text;

use App\Support\Crypto\BlindIndex\Normalizer;

/**
 * Whether two names, printed in different places, could be the same party.
 *
 * Exact equality fails on every real case: a Romanian document writes "SANDU LAURENTIU-CONSTANTIN"
 * where an account says "Laur Sandu", and a bank writes "KAUFLAND 1234 BUCURESTI" where a receipt
 * says "Kaufland Romania SCS". Two questions, answered differently:
 *
 * - **Is this printed name that person?** {@see self::contains()} — every word of the short name
 *   appears in the long one. Strict, because the answer decides which way money moves.
 * - **Could these two rows name the same shop?** {@see self::sharesName()} — they have a distinctive
 *   word in common. Loose, because the answer only gathers rows for a person to look at.
 *
 * Here rather than in either module that needs it: `Documents` asks the first, `Wallet` asks the
 * second, and `deptrac.php` lets neither depend on the other.
 */
final class PartyName
{
    /**
     * Below this a prefix is not evidence of anything — "Io" starts half the names in the country.
     */
    private const int MIN_PREFIX_LENGTH = 3;

    /**
     * Every word of `$shorter` appears in `$longer`, whole or as the start of a word.
     *
     * Directional on purpose. "Laur" may stand for "LAURENTIU" on a document; "Ionescu" must not
     * stand for "Ion", or every Ionescu matches every Ion. So the side expected to be abbreviated is
     * the one searched *for*.
     */
    public static function contains(string $longer, string $shorter): bool
    {
        $haystack = self::words($longer);
        $needles = self::words($shorter);

        if ($needles === []) {
            return false;
        }

        foreach ($needles as $needle) {
            if (! self::appears($needle, $haystack)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The two names have a distinctive word in common.
     *
     * Containment cannot answer this: "Kaufland Romania SCS" and "KAUFLAND 1234 BUCURESTI" are one
     * shop, and neither name's words are all inside the other's. What they share is the one word that
     * identifies the shop. Legal forms and ubiquitous words are ignored — `config/party_names.php` —
     * or "Kaufland Romania SCS" and "Lidl Romania SCS" would share two words and look like one shop.
     *
     * Words shorter than the prefix minimum are ignored as well, for the same reason they are not
     * accepted as prefixes: they are not evidence of anything.
     */
    public static function sharesName(string $first, string $second): bool
    {
        $generic = config('party_names.generic_words');
        $ignored = array_flip(array_filter(is_array($generic) ? $generic : [], is_string(...)));

        $distinctive = static fn (string $word): bool => mb_strlen($word) >= self::MIN_PREFIX_LENGTH
            && ! isset($ignored[$word]);

        $firstWords = array_flip(array_filter(self::words($first), $distinctive));

        foreach (array_filter(self::words($second), $distinctive) as $word) {
            if (isset($firstWords[$word])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The words of a name, folded the way the blind indexes fold them.
     *
     * Diacritics and case go through `Normalizer::text()`, so "Ștefan" and "STEFAN" are one word.
     * Dots close up rather than split — "S.C. SENDWISE S.R.L." is three words, not six letters and
     * a name — while hyphens split, because "LAURENTIU-CONSTANTIN" is two given names.
     *
     * @return list<string>
     */
    public static function words(string $value): array
    {
        $folded = str_replace('.', '', Normalizer::text($value));

        return array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $folded) ?: [],
            static fn (string $word): bool => $word !== '',
        ));
    }

    /**
     * @param  list<string>  $haystack
     */
    private static function appears(string $needle, array $haystack): bool
    {
        foreach ($haystack as $word) {
            if ($word === $needle || (mb_strlen($needle) >= self::MIN_PREFIX_LENGTH && str_starts_with($word, $needle))) {
                return true;
            }
        }

        return false;
    }
}
