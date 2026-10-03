<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Actions;

use Illuminate\Support\Facades\DB;
use Kern\TaxRomania\Wallet\Enums\WalletAuditEvent;
use Kern\TaxRomania\Wallet\Models\Counterparty;
use Kern\TaxRomania\Wallet\Services\WalletAudit;

/**
 * What was decided about a counterparty, forgotten.
 *
 * ## What it does not touch
 *
 * The transactions. A party is what the ledger remembers *about* a name, not the money that moved
 * under it: forgetting `Mega Image` leaves every payment to Mega Image exactly where it was, still
 * classified as whatever it was classified as. The next statement naming it writes the party down
 * again, unanswered — which is the point of forgetting one that was answered wrongly.
 *
 * So this is a smaller thing than it looks: it clears answers, not history. The alternative — a
 * delete that took the transactions with it — would be a way to lose a year of records by tidying a
 * list of names.
 */
final readonly class ForgetCounterparties
{
    public function __construct(private WalletAudit $audit)
    {
    }

    /**
     * @param  non-empty-list<Counterparty>  $parties
     * @return int how many rules were forgotten
     */
    public function __invoke(array $parties, ?string $actorId = null): int
    {
        $subjectId = $parties[0]->tax_subject_id;

        $forgotten = DB::transaction(static function () use ($parties): int {
            $count = 0;

            foreach ($parties as $party) {
                // The tag links go with it: the pivot's rows describe a party that is about to stop
                // existing, and a cascade is not something to rely on for a decision this explicit.
                $party->tags()->detach();
                $party->delete();
                $count++;
            }

            return $count;
        });

        $this->audit->record(
            subjectId: $subjectId,
            actorId: $actorId,
            eventType: WalletAuditEvent::COUNTERPARTIES_FORGOTTEN->value,
            resourceType: WalletAuditEvent::COUNTERPARTIES_FORGOTTEN->resourceType(),
            resourceId: $parties[0]->id,
            // A count, never the names: this trail is read more widely than the ledger it describes.
            payload: ['counterparties_forgotten' => $forgotten],
        );

        return $forgotten;
    }
}
