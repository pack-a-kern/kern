<?php

declare(strict_types=1);

namespace App\Domain\Documents\Queries;

use App\Domain\Documents\Enums\ExtractionStatus;
use Illuminate\Support\Facades\DB;

/**
 * Which of these documents a person has already dealt with.
 *
 * "Dealt with" has one definition in this app — an accepted or reviewed extraction, or a
 * transaction built from the document — and it was written for the inbox's `needs_review` filter.
 * This is that same rule, extracted so the filter and the stage shown on screen cannot drift into
 * disagreeing about which documents are finished.
 *
 * Both queries are constrained to the subject. Neither could leak another subject's data outward —
 * the caller's own query is scoped and these only ever classify ids it already holds — but letting
 * a stranger's row decide what a person sees is precisely the reasoning that produces tenancy bugs.
 */
final readonly class SettledDocuments
{
    /**
     * @param  list<string>  $documentIds
     * @return array<string, true> keyed by document id, present only for settled documents
     */
    public function __invoke(string $subjectId, array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        $reviewed = DB::table('document_extractions')
            ->join('documents', 'documents.id', '=', 'document_extractions.document_id')
            ->where('documents.tax_subject_id', $subjectId)
            ->whereIn('document_extractions.document_id', $documentIds)
            ->whereIn('document_extractions.status', [
                ExtractionStatus::ACCEPTED->value,
                ExtractionStatus::REVIEWED->value,
            ])
            ->pluck('document_extractions.document_id');

        $used = DB::table('financial_transactions')
            ->where('tax_subject_id', $subjectId)
            ->whereIn('document_id', $documentIds)
            ->whereNotNull('document_id')
            ->pluck('document_id');

        $settled = [];

        foreach ([$reviewed, $used] as $ids) {
            foreach ($ids as $id) {
                if (is_scalar($id)) {
                    $settled[(string) $id] = true;
                }
            }
        }

        return $settled;
    }
}
