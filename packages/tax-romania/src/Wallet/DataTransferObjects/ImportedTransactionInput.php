<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\DataTransferObjects;

/**
 * One statement line, as the ledger will store it.
 *
 * Flat and already-decided: by the time a row reaches this object a person has confirmed the
 * mapping that produced its figures and the answer that gives it a category, so nothing here is
 * parsed, guessed or looked up again.
 */
final readonly class ImportedTransactionInput
{
    /**
     * @param  string  $rowId  the staged row this came from, so the caller can mark it imported
     * @param  list<string>  $tagIds
     */
    public function __construct(
        public string $rowId,
        public string $bookedAt,
        public int $amountMinor,
        public string $currencyCode,
        public string $description,
        public string $counterparty,
        public ?string $counterpartyBlindIndex,
        public ?string $fingerprint,
        public string $importId,
        public ?string $bankId = null,
        public ?string $categoryCode = null,
        public ?string $countryCode = null,
        public array $tagIds = [],
    ) {
    }
}
