<?php

declare(strict_types=1);

namespace App\Domain\Banks\Services;

use App\Domain\Banks\DataTransferObjects\EcbInstitution;
use App\Domain\Banks\Parsing\LegalForms;

/**
 * The name a person reads, built from the registered one — no name is ever written by hand.
 *
 * **A bank** is its registered name without the legal form that closes it: "BANCA TRANSILVANIA
 * S.A." is Banca Transilvania. Only a closing form is removed, so a bank whose name starts with
 * letters that happen to spell one keeps them.
 *
 * **A branch** is named in Romanian registers as the bank, its legal form, then where it is from and
 * that it is a branch: "PKO BANK POLSKI S.A. VARSOVIA, SUCURSALA BUCURESTI". Everything before the
 * first legal form is the bank's name, so that is what is kept — PKO Bank Polski, not the parent's
 * registered "Powszechna Kasa Oszczędności Bank Polski S.A.". Where the branch's name carries no legal
 * form to cut at, the parent's name is used instead, without its own: Bank of China's Romanian branch
 * reads "BANK OF CHINA (CENTRAL AND EASTERN EUROPE) LIMITED SUCURSALA BUCURESTI", and its Hungarian
 * parent's form is written out in full.
 *
 * The legal forms come from ISO 20275, for the country the name is registered in. An all-capitals
 * result then gets ordinary capitals; a name its owner wrote in mixed case is left as written.
 *
 * What it cannot produce is a brand shortcut — "BCR", "BT". No official source records those, which
 * is why the bank's 4-letter code is shown beside this name rather than instead of it.
 */
final class BankNameBuilder
{
    /**
     * A first word this short in an all-capitals name is an acronym — BRD, CEC, ING, TBI, PKO — and
     * keeps its capitals. Four letters is already a word: EXIM, SALT, BANK.
     */
    private const int ACRONYM_MAX_LETTERS = 3;

    /** Later words this short are joining words — DE, SI — and are lower-cased, as in "Credite si Investitii". */
    private const int JOINING_WORD_MAX_LETTERS = 2;

    private const string TRAILING_PUNCTUATION = " \t,;-–—";

    public function build(EcbInstitution $institution, LegalForms $legalForms): string
    {
        if ($institution->parentName === '') {
            return $this->withOrdinaryCapitals(
                $this->withoutClosingForm($institution->name, [$institution->countryCode], $legalForms),
            );
        }

        $branchCountries = array_values(array_unique(array_filter([$institution->parentCountryCode, $institution->countryCode])));
        $name = $this->beforeFirstForm($institution->name, $branchCountries, $legalForms)
            ?? $this->withoutClosingForm($institution->parentName, $branchCountries, $legalForms);

        return $this->withOrdinaryCapitals($name);
    }

    /**
     * The words before the first legal form, or null when the name has none after its first word.
     *
     * @param  list<string>  $countries
     */
    private function beforeFirstForm(string $name, array $countries, LegalForms $legalForms): ?string
    {
        $words = self::words($name);

        for ($index = 1; $index < count($words); $index++) {
            if ($legalForms->lengthAt($words, $index, $countries) > 0) {
                return self::joined(array_slice($words, 0, $index));
            }
        }

        return null;
    }

    /**
     * The name without the legal form that ends it, or the name itself when nothing is left or no
     * form ends it.
     *
     * @param  list<string>  $countries
     */
    private function withoutClosingForm(string $name, array $countries, LegalForms $legalForms): string
    {
        $words = self::words($name);

        for ($index = 1; $index < count($words); $index++) {
            if ($index + $legalForms->lengthAt($words, $index, $countries) === count($words)) {
                return self::joined(array_slice($words, 0, $index));
            }
        }

        return self::joined($words);
    }

    private function withOrdinaryCapitals(string $name): string
    {
        if (mb_strtoupper($name) !== $name) {
            return $name;
        }

        $words = explode(' ', $name);

        foreach ($words as $index => $word) {
            $letters = mb_strlen((string) preg_replace('/[^\p{L}]/u', '', $word));
            $lower = mb_strtolower($word);

            $words[$index] = match (true) {
                $index === 0 && $letters <= self::ACRONYM_MAX_LETTERS => $word,
                $index > 0 && $letters <= self::JOINING_WORD_MAX_LETTERS => $lower,
                default => mb_convert_case($lower, MB_CASE_TITLE),
            };
        }

        return implode(' ', $words);
    }

    /**
     * @return list<string>
     */
    private static function words(string $name): array
    {
        return preg_split('/\s+/u', trim($name)) ?: [];
    }

    /**
     * @param  list<string>  $words
     */
    private static function joined(array $words): string
    {
        return trim(implode(' ', $words), self::TRAILING_PUNCTUATION);
    }
}
