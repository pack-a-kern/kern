<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\Parsing;

/**
 * The legal forms each country uses, as ISO 20275 lists them — both the abbreviation (`SA`, `N.V.`,
 * `EAD`) and the name written out (`Zártkörűen működő részvénytársaság`), because a registered name
 * may carry either.
 *
 * Compared word by word on letters and digits only, in capitals. That makes `S.A.`, `S.A`, `SA` and
 * `s.a.` one form, as they are on the page — and it is also what makes Romania's entry match at all:
 * the ISO file writes it as `SA` followed by a non-breaking space, which a comparison that only
 * removed dots and ordinary spaces never saw.
 */
final readonly class LegalForms
{
    /**
     * @param  array<string, list<list<string>>>  $byCountry  each form as its normalised words, keyed by ISO 3166-1 alpha-2
     */
    public function __construct(private array $byCountry)
    {
    }

    public static function normalise(string $word): string
    {
        return mb_strtoupper((string) preg_replace('/[^\p{L}\p{N}]/u', '', $word));
    }

    /**
     * How many of `$words`, starting at `$index`, spell a legal form of one of `$countries` — the
     * longest one, so the full name wins over an abbreviation it happens to begin with. 0 when none.
     *
     * @param  list<string>  $words  the name's words as written
     * @param  list<string>  $countries
     */
    public function lengthAt(array $words, int $index, array $countries): int
    {
        $longest = 0;

        foreach ($countries as $country) {
            foreach ($this->byCountry[strtoupper($country)] ?? [] as $form) {
                $length = count($form);

                if ($length <= $longest || $index + $length > count($words)) {
                    continue;
                }

                $spelled = array_map(self::normalise(...), array_slice($words, $index, $length));

                if ($spelled === $form) {
                    $longest = $length;
                }
            }
        }

        return $longest;
    }
}
