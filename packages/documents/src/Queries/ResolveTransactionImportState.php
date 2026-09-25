<?php

declare(strict_types=1);

namespace Kern\Documents\Queries;

use Illuminate\Support\Facades\DB;
use Kern\Documents\Enums\DocumentStatus;
use Kern\Documents\Enums\ExtractionStatus;
use Kern\Documents\Enums\TransactionImportState;
use Kern\Documents\Models\Document;
use Kern\Platform\Tenancy\Tenancy;

/**
 * Where a document stands on the way to becoming a transaction.
 *
 * One place, read by the screen that shows the button, the endpoint that refuses the request, and
 * the badge in the inbox — see {@see TransactionImportState} for why that matters.
 *
 * `forMany` exists because a list needs the same answer for a page of documents, and asking three
 * questions per row would be seventy-five queries for a page of twenty-five. It asks them once, as
 * sets. `__invoke` is the single-document case expressed in terms of it, so there is one rule and
 * not two that agree today.
 *
 * Every query is constrained to the subject. None could leak another subject's data outward — the
 * caller's own query is scoped and these only ever classify ids it already holds — but letting a
 * stranger's row decide what a person sees is precisely the reasoning that produces tenancy bugs.
 */
final readonly class ResolveTransactionImportState
{
    /** The statuses that mean a person has been through the reading. */
    public const array ACCEPTED_STATUSES = [
        ExtractionStatus::ACCEPTED->value,
        ExtractionStatus::REVIEWED->value,
    ];

    public function __invoke(Document $document): TransactionImportState
    {
        return $this->forMany($document->tenantId(), [$document])[$document->id]
            ?? TransactionImportState::UNAVAILABLE;
    }

    /**
     * @param  list<Document>  $documents
     * @return array<string, TransactionImportState> keyed by document id, one entry per document
     */
    public function forMany(string $subjectId, array $documents): array
    {
        if ($documents === []) {
            return [];
        }

        $ids = array_map(static fn (Document $document): string => $document->id, $documents);

        $imported = $this->idsWithTransactions($subjectId, $ids) + $this->idsWithRentals($subjectId, $ids);
        $accepted = $this->idsWithAcceptedReading($subjectId, $ids);

        $states = [];

        foreach ($documents as $document) {
            $states[$document->id] = match (true) {
                // Asked first, because it is the one answer that stays true whatever else happened:
                // a document that already produced its record — a transaction, or a rental — is
                // finished with, however it got there.
                isset($imported[$document->id]) => TransactionImportState::IMPORTED,
                $document->status !== DocumentStatus::READY => TransactionImportState::UNAVAILABLE,
                isset($accepted[$document->id]) => TransactionImportState::AVAILABLE,
                // Everything else: a proposal nobody has been through, and also a document nothing
                // could read at all. Those look different on the review screen — one offers Accept,
                // the other offers Retry — but they are the same answer to this question. An earlier
                // version let an unread document through on the reasoning that a person could type
                // the figures by hand; it produced a "To import" badge on a document where nothing
                // had been read, which is a promise the screen cannot keep.
                default => TransactionImportState::AWAITING_ACCEPTANCE,
            };
        }

        return $states;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    private function idsWithTransactions(string $subjectId, array $ids): array
    {
        return $this->keyed(DB::table('financial_transactions')
            ->where(Tenancy::column(), $subjectId)
            ->whereIn('document_id', $ids)
            ->whereNotNull('document_id')
            ->pluck('document_id'));
    }

    /**
     * Contracts a rental was created from.
     *
     * A rental contract produces a rental rather than a transaction — its income is what the
     * contract owes, not money that moved once — so for a contract, a rental made from it is what
     * "imported" means. By table rather than model: `Documents` may not depend on `Rental`.
     *
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    private function idsWithRentals(string $subjectId, array $ids): array
    {
        return $this->keyed(DB::table('rental_contracts')
            ->where(Tenancy::column(), $subjectId)
            ->whereIn('source_document_id', $ids)
            ->pluck('source_document_id'));
    }

    /**
     * Documents with a reading a person has been through.
     *
     * `document_extractions` carries no `tax_subject_id` of its own — it is reached through its
     * document — so the boundary is enforced by joining back to it.
     *
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    private function idsWithAcceptedReading(string $subjectId, array $ids): array
    {
        return $this->keyed(DB::table('document_extractions')
            ->join('documents', 'documents.id', '=', 'document_extractions.document_id')
            ->where('documents.' . Tenancy::column(), $subjectId)
            ->whereIn('document_extractions.document_id', $ids)
            ->whereIn('document_extractions.status', self::ACCEPTED_STATUSES)
            ->pluck('document_extractions.document_id'));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>  $ids
     * @return array<string, true>
     */
    private function keyed(mixed $ids): array
    {
        $keyed = [];

        foreach ($ids as $id) {
            if (is_scalar($id)) {
                $keyed[(string) $id] = true;
            }
        }

        return $keyed;
    }
}
