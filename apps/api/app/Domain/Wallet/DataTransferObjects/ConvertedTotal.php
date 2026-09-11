<?php

declare(strict_types=1);

namespace App\Domain\Wallet\DataTransferObjects;

/**
 * Per-currency amounts added up into one, for a screen to show.
 *
 * Carries the rates it used and the dates they were published, because a single number without them
 * is a claim nobody can check. The screen shows both — "≈ 602.343 RON · curs BNR 09.09.2026" — so a
 * reader knows this is an indication, not a figure anything was decided on.
 *
 * `complete` is false when at least one currency had no published rate and was therefore left out.
 * A total that quietly omits a currency reads as smaller wealth rather than as missing data, so the
 * screen has to be able to say so.
 *
 * **Never a fiscal figure.** `BLUEPRINT.md` §7.4 requires each `TaxRule` to choose its own
 * conversion method and record the provenance beside the result; this is a dashboard convenience
 * computed on demand and never stored.
 */
final readonly class ConvertedTotal
{
    /**
     * @param  array<string, array{rate: string, rate_date: string, source: string}>  $rates
     * @param  list<string>  $missing  currencies with no published rate, excluded from `amountMinor`
     */
    public function __construct(
        public string $currencyCode,
        public int $amountMinor,
        public array $rates,
        public array $missing,
    ) {
    }

    public function complete(): bool
    {
        return $this->missing === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency_code' => $this->currencyCode,
            'amount_minor' => $this->amountMinor,
            'rates' => $this->rates,
            'missing' => $this->missing,
            'complete' => $this->complete(),
        ];
    }
}
