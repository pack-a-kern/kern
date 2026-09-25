<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\DataTransferObjects;

use DateTimeImmutable;
use Kern\TaxRomania\TaxEngine\Enums\TaxFactStatus;
use Kern\TaxRomania\TaxEngine\ValueObjects\Money;

/**
 * A pure, immutable snapshot of one `Kern\TaxRomania\TaxEngine\Models\TaxFact` row — what a
 * `Kern\TaxRomania\TaxEngine\Contracts\TaxRuleContract` implementation actually receives, never the
 * Eloquent model itself (`docs/AGENTS.md` §6/§11.2 — a rule must not be able to lazy-load a
 * relation or trigger a query merely by touching its input). All amounts are already converted to
 * RON `Money` — see `Kern\TaxRomania\TaxEngine\Services\FxConversionPolicy` — a rule never converts
 * currency itself.
 *
 * Carries every §10.3 date this fact might have populated; a rule reads only the one(s) its own
 * logic cares about ("Rule-ul decide ce dată contează" — `BLUEPRINT.md` §10.3), never assumes which
 * is set.
 */
final readonly class TaxFactSnapshot
{
    public function __construct(
        public string $id,
        public string $categoryCode,
        public ?string $subtypeCode,
        public DateTimeImmutable $recognitionDate,
        public ?DateTimeImmutable $cashDate,
        public ?DateTimeImmutable $earnedAt,
        public ?DateTimeImmutable $receivedAt,
        public ?DateTimeImmutable $paidAt,
        public ?DateTimeImmutable $distributedAt,
        public ?DateTimeImmutable $contractDate,
        public ?DateTimeImmutable $transactionDate,
        public Money $grossAmountRon,
        public ?Money $deductibleAmountRon,
        public ?Money $withheldTaxRon,
        public TaxFactStatus $status,
    ) {
    }
}
