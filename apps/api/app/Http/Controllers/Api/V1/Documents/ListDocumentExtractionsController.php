<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentExtraction;
use App\Domain\Documents\Parsing\ExtractionSchema;
use App\Domain\Documents\Payments\ResolvePaymentParties;
use App\Domain\Subjects\Models\TaxSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/subjects/{subject}/documents/{document}/extractions` — `BLUEPRINT.md` §14.6's human
 * review queue: every extraction attempt for this document, decrypted proposed fields included,
 * cursor-paginated (`BLUEPRINT.md` §18), so a reviewer can accept or correct one
 * (`AcceptDocumentExtractionController`/`CorrectDocumentExtractionController`).
 *
 * `{document}` is resolved through `Document::query()` first — tenant-scoped by
 * `Kern\Platform\Tenancy\ScopedToTenant` via the `tax-subject` middleware already bound
 * for this route group — so a document belonging to another subject 404s before this ever touches
 * `document_extractions`, a table with no `tax_subject_id` column of its own to scope on
 * directly (`App\Domain\Documents\Models\DocumentExtraction`'s own docblock explains why).
 */
final class ListDocumentExtractionsController
{
    public function __invoke(
        Request $request,
        string $subject,
        string $document,
        ResolvePaymentParties $parties,
    ): JsonResponse {
        $documentModel = Document::query()->findOrFail($document);
        $taxSubject = TaxSubject::query()->findOrFail($documentModel->tax_subject_id);

        $cursor = $request->query('cursor');
        $perPage = max(1, min(100, $request->integer('per_page', 20)));

        $paginator = DocumentExtraction::query()
            ->where('document_id', $documentModel->id)
            ->orderBy('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', is_string($cursor) ? $cursor : null)
            ->through(static fn (DocumentExtraction $extraction): array => self::summarize(
                $extraction->withEncryptionSubjectId($documentModel->tax_subject_id),
                $taxSubject,
                $parties,
            ));

        return response()->json($paginator);
    }

    /**
     * @return array<string, mixed>
     */
    private static function summarize(
        DocumentExtraction $extraction,
        TaxSubject $subject,
        ResolvePaymentParties $parties,
    ): array {
        $fields = json_decode($extraction->result_ciphertext, true, flags: JSON_THROW_ON_ERROR);

        return [
            'id' => $extraction->id,
            'extractor_type' => $extraction->extractor_type->value,
            // Which reader produced this, and which version of it. A figure from an older reading
            // has to be tellable from a newer one — the same reason `TaxRule` carries a version.
            'extractor_version' => $extraction->extractor_version,
            'model_provider' => $extraction->model_provider,
            'model_name' => $extraction->model_name,
            // What it cost. Null for a reading nobody measured — a parser, or a provider that keeps
            // no accounting — rather than a zero that would read as "free".
            'duration_ms' => $extraction->duration_ms,
            'prompt_tokens' => $extraction->prompt_tokens,
            'completion_tokens' => $extraction->completion_tokens,
            'schema_version' => $extraction->schema_version,
            'confidence' => $extraction->confidence_decimal,
            'status' => $extraction->status->value,
            'reviewed_by' => $extraction->reviewed_by,
            'proposed_fields' => $fields,
            // What the reading *means* for this subject, kept beside the reading rather than mixed
            // into it. The reading is a record of the document — `payer` and `beneficiary` as
            // printed — and stays true whoever is looking at it; this is the part that changes if
            // the same document is ever attached to another subject.
            'payment_parties' => self::paymentParties(is_array($fields) ? $fields : [], $subject, $parties),
            'created_at' => $extraction->created_at?->toIso8601String(),
            'updated_at' => $extraction->updated_at?->toIso8601String(),
        ];
    }

    /**
     * One entry per payment, in the same order, saying which side the subject is on.
     *
     * Aligned by position rather than keyed, because a payment has no identity of its own — it is
     * the third line of a decision, and nothing about it is stable enough to key on.
     *
     * @param  array<array-key, mixed>  $fields
     * @return list<array{direction: string|null, counterparty: string|null}>
     */
    private static function paymentParties(array $fields, TaxSubject $subject, ResolvePaymentParties $parties): array
    {
        $payments = $fields[ExtractionSchema::FIELD_PAYMENTS] ?? null;

        if (! is_array($payments)) {
            return [];
        }

        $resolved = [];

        foreach ($payments as $payment) {
            $resolved[] = is_array($payment)
                ? $parties($subject, $payment)
                : ['direction' => null, 'counterparty' => null];
        }

        return $resolved;
    }
}
