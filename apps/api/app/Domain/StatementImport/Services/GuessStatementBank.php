<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Services;

use App\Domain\Banks\Models\Bank;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Which bank issued a statement, from what the statement itself says.
 *
 * Two ways, in this order:
 *
 *  - **The statement's own IBAN**, in the lines above the table. A Romanian IBAN carries the bank's
 *    code in letters 5 to 8 (`RO49 BTRL …`), and that code opens the bank's BIC (`BTRLRO22`), so the
 *    match is exact and nothing is sent anywhere.
 *  - **What the model named**, when no IBAN was found: a BIC, its four-letter code, or a name.
 *
 * Either way the answer is one bank or none. Closed banks count — a statement from before a merger
 * was issued by the bank that existed then — but where a closed and a current bank both fit, the
 * current one is meant. Any other tie is not a guess worth making: the field is left for the
 * person, who is asked for it on the same screen.
 */
final readonly class GuessStatementBank
{
    /** A country, two check digits, and a bank code written in letters — optionally spaced. */
    private const string IBAN = '/(?<![A-Z0-9])([A-Z]{2})\d{2}\s?([A-Z]{4})/';

    /** @param list<list<string>> $preamble the lines above the header */
    public function fromIban(array $preamble): ?Bank
    {
        $found = [];

        foreach ($preamble as $line) {
            foreach ($line as $cell) {
                if (preg_match_all(self::IBAN, Str::upper($cell), $matches, PREG_SET_ORDER) > 0) {
                    foreach ($matches as $match) {
                        $found[$match[1] . ':' . $match[2]] = [$match[1], $match[2]];
                    }
                }
            }
        }

        // The account's own IBAN is the one in the heading; a heading naming two banks' accounts
        // does not say which one this statement is from.
        if (count($found) !== 1) {
            return null;
        }

        [$country, $code] = array_values($found)[0];

        return $this->byCode($code, $country);
    }

    /** The bank the model named — a BIC, its four-letter code, or a name — or null. */
    public function fromAnswer(mixed $answer): ?Bank
    {
        if (! is_string($answer) || trim($answer) === '') {
            return null;
        }

        $answer = trim($answer);

        if (preg_match('/^[A-Z]{4}([A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?)?$/', Str::upper($answer)) === 1) {
            $bank = $this->byCode(substr(Str::upper($answer), 0, 4), strlen($answer) >= 6 ? substr(Str::upper($answer), 4, 2) : null);

            if ($bank !== null) {
                return $bank;
            }
        }

        $wanted = self::normalised($answer);

        if ($wanted === '') {
            return null;
        }

        $matches = Bank::query()
            ->get(['id', 'name', 'legal_name', 'bic', 'country_code', 'active'])
            ->filter(static function (Bank $bank) use ($wanted): bool {
                foreach ([$bank->name, $bank->legal_name] as $name) {
                    $known = self::normalised($name);

                    if ($known !== '' && (str_contains($known, $wanted) || str_contains($wanted, $known))) {
                        return true;
                    }
                }

                return false;
            });

        return self::one($matches);
    }

    private function byCode(string $code, ?string $country): ?Bank
    {
        return self::one(Bank::query()
            ->where('bic', 'like', $code . '%')
            ->when($country !== null, static fn ($query) => $query->where('country_code', $country))
            ->get());
    }

    /**
     * The one bank that fits, or the one current bank among several — or none.
     *
     * @param  Collection<int, Bank>  $matches
     */
    private static function one(Collection $matches): ?Bank
    {
        if ($matches->count() === 1) {
            return $matches->first();
        }

        $current = $matches->filter(static fn (Bank $bank): bool => $bank->active);

        return $current->count() === 1 ? $current->first() : null;
    }

    /**
     * A name as it compares: no case, no diacritics, no punctuation, no legal form.
     *
     * "BANCA TRANSILVANIA S.A." and "Banca Transilvania" are one bank, and the model writes either.
     */
    private static function normalised(string $name): string
    {
        $plain = Str::lower(Str::ascii($name));
        $plain = (string) preg_replace('/[^a-z0-9 ]+/', ' ', $plain);
        $plain = (string) preg_replace('/\b(s ?a|sa|plc|ag|nv)\b/', ' ', $plain);

        return trim((string) preg_replace('/\s+/', ' ', $plain));
    }
}
