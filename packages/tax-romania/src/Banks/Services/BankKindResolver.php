<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\TaxRomania\Banks\Enums\BankKind;

/**
 * What kind of institution a registration number says this is.
 *
 * The codes are national — see `config/banks.php` for the table and where it comes from — so the
 * country decides which patterns apply and the code is matched within that country alone. Both are
 * columns the import already stores, so nothing here parses a country out of a code.
 *
 * ## Why matching is not `str_contains`
 *
 * Because a pattern found in the middle of a number would match by accident, and these numbers carry
 * a county code and an order number we do not fully understand. A pattern must be where a prefix
 * belongs: at the start of the code, or at the start of what follows the country code — the ECB
 * prints `RB-PJR` as `RORBPJR…` and `ROCC-CO` as `ROCCCO…`, so both shapes occur and only those two.
 *
 * ## Why both sides are stripped
 *
 * So the config can be written the way the regulation writes it, dashes and all, and still match a
 * file that prints none. Comparing the two spellings directly would mean either a config nobody can
 * check against its source, or a transformation applied when the list is read rather than when it is
 * used — and the second is the one that gets forgotten.
 */
final readonly class BankKindResolver
{
    public function __construct(private ConfigRepository $config)
    {
    }

    public function for(string $countryCode, string $ecbCode): BankKind
    {
        $country = self::simplify($countryCode);
        $code = self::simplify($ecbCode);

        if ($code === '') {
            return BankKind::UNKNOWN;
        }

        /** @var array<string, array<string, list<string>>> $table */
        $table = $this->config->get('banks.kinds', []);

        foreach ($table[strtoupper($countryCode)] ?? [] as $kind => $patterns) {
            foreach ($patterns as $pattern) {
                if (self::matches($code, $country, self::simplify($pattern))) {
                    return BankKind::tryFrom($kind) ?? BankKind::UNKNOWN;
                }
            }
        }

        return BankKind::UNKNOWN;
    }

    /**
     * At the start, or at the start of what follows the country code.
     *
     * The second case is not a convenience: `RB-PJR` never appears at position zero, because the file
     * writes `RORBPJR…`. Anchoring both ways is what lets the table hold the official spelling.
     */
    private static function matches(string $code, string $country, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        return str_starts_with($code, $pattern) || str_starts_with($code, $country . $pattern);
    }

    /** Letters and digits, lowercased — so `RB-PJR` and `rorbpjr40008` can be compared at all. */
    private static function simplify(string $value): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }
}
