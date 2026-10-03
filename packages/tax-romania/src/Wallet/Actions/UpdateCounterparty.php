<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kern\TaxRomania\Wallet\Enums\ProposalSource;
use Kern\TaxRomania\Wallet\Enums\WalletAuditEvent;
use Kern\TaxRomania\Wallet\Models\Counterparty;
use Kern\TaxRomania\Wallet\Services\CounterpartyTransactions;
use Kern\TaxRomania\Wallet\Services\WalletAudit;

/**
 * What a party is, for one direction — and the rows that follow from saying so.
 *
 * The answer governs two things, and before this screen existed only the first: the next statement
 * that names this party, and the rows already in the ledger that nobody has answered. A person who
 * decides "money in from her is a loan repayment" means both, and a rule that only applies to the
 * future is a rule they have to keep re-deciding.
 *
 * Marked `PERSON` whatever was there before. A model's proposal that somebody has now confirmed or
 * overwritten is a person's answer, and the difference matters: {@see ClassifyKnownCounterparties}
 * leaves a person's answer alone and will revisit a model's.
 */
final readonly class UpdateCounterparty
{
    public function __construct(
        private CounterpartyTransactions $rows,
        private WalletAudit $audit,
    ) {
    }

    /**
     * @param  list<string>  $tagIds
     * @return int how many transactions took the answer
     */
    public function __invoke(
        Counterparty $counterparty,
        ?string $categoryCode,
        ?string $countryCode,
        array $tagIds,
        bool $replace = false,
        ?string $actorId = null,
    ): int {
        DB::transaction(function () use ($counterparty, $categoryCode, $countryCode, $tagIds): void {
            $counterparty->category_code = $categoryCode;
            $counterparty->country_code = $countryCode;
            $counterparty->source = ProposalSource::PERSON;
            $counterparty->analysed_at = CarbonImmutable::now('UTC');
            $counterparty->save();

            $counterparty->tags()->sync(array_fill_keys(
                $tagIds,
                ['tax_subject_id' => $counterparty->tax_subject_id],
            ));
        });

        $applied = $this->rows->applyAnswer(
            $counterparty->tax_subject_id,
            $counterparty,
            $categoryCode,
            $tagIds,
            $replace,
        );

        $this->audit->record(
            subjectId: $counterparty->tax_subject_id,
            actorId: $actorId,
            eventType: WalletAuditEvent::COUNTERPARTY_UPDATED->value,
            resourceType: WalletAuditEvent::COUNTERPARTY_UPDATED->resourceType(),
            resourceId: $counterparty->id,
            // The name is not recorded: it is the party's identity and this trail is read by
            // anybody who may read the audit, which is a wider set than may read the ledger.
            payload: [
                'direction' => $counterparty->direction->value,
                'category_code' => $categoryCode,
                'transactions_updated' => $applied,
                // Recorded because it is the destructive half: it says answers that existed were
                // replaced, which the count alone does not.
                'replaced_existing' => $replace,
            ],
        );

        return $applied;
    }
}
