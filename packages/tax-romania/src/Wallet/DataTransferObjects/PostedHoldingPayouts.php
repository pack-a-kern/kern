<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\DataTransferObjects;

/** What one run of the holdings' payouts did: how many lots were credited, and what was left for later, by why. */
final readonly class PostedHoldingPayouts
{
    /** @param array<string, int> $skipped by `SkippedPayout` value: payouts left uncredited this run */
    public function __construct(
        public int $posted,
        public array $skipped,
    ) {
    }
}
