<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentExtraction;
use App\Domain\Subjects\Tenancy\TenantContext;
use App\Domain\Wallet\Actions\RecordFinancialTransaction;
use App\Domain\Wallet\DataTransferObjects\RecordFinancialTransactionInput;
use App\Domain\Wallet\Enums\RecordSource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/subjects/{subject}/documents/{document}/transaction` — turn a document into a
 * wallet transaction, with the document kept as its evidence.
 *
 * ## A person does this, not the pipeline
 *
 * The amounts arrive in the request rather than being read from the extraction, even though the
 * extraction is usually where they came from. That is deliberate. A parser proposes and a human
 * confirms — and confirming means the figures that reach `financial_transactions` are the ones the
 * person actually saw and agreed to, not a set the server re-derived after they clicked. It also
 * means a document no parser could read still becomes a transaction: the person types what they
 * see, and the document is attached exactly the same way.
 *
 * `document_extraction_id` records *which reading* was trusted, because a document can be read more
 * than once — a parser proposes, a human corrects — and the transaction should say which one it
 * came from. Optional: a hand-typed transaction from an unparsed document has no reading behind it.
 *
 * ## Why not a job, and why not on accept
 *
 * Doing this automatically when an extraction is accepted would make accepting a *reading* also
 * mean creating a *financial record*, which are separate decisions: a receipt might be filed for
 * reference and never belong in the ledger. Keeping them apart also keeps the review step honest —
 * a person can correct fields without every correction moving money.
 */
final class CreateTransactionFromDocumentController extends Controller
{
    public function __invoke(
        Request $request,
        string $subject,
        string $document,
        RecordFinancialTransaction $record,
        TenantContext $tenant,
    ): JsonResponse {
        // Tenant-scoped: another subject's document is not found rather than used as evidence.
        $model = Document::query()->findOrFail($document);

        $validated = $request->validate([
            'account_id' => ['required', 'uuid'],
            // Signed minor units, so the direction is the sign — a receipt is negative, a refund or
            // an incoming invoice positive. A separate direction field would let the two disagree.
            'amount_minor' => ['required', 'integer', 'not_in:0'],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'booked_at' => ['required', 'date'],
            'value_at' => ['sometimes', 'nullable', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'counterparty' => ['sometimes', 'nullable', 'string', 'max:200'],
            'document_extraction_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $extractionId = $this->extractionId($validated, $model);

        $transaction = $record(new RecordFinancialTransactionInput(
            taxSubjectId: $tenant->currentSubjectId(),
            accountId: (string) $validated['account_id'],
            bookedAt: (string) $validated['booked_at'],
            amountMinor: (int) $validated['amount_minor'],
            currencyCode: (string) $validated['currency_code'],
            description: (string) $validated['description'],
            counterparty: is_string($validated['counterparty'] ?? null) ? $validated['counterparty'] : '',
            valueAt: is_string($validated['value_at'] ?? null) ? $validated['value_at'] : null,
            // Not MANUAL: "someone typed this in" and "this came from a document still in the
            // object store, with its hash and its scan record" are different provenance.
            source: RecordSource::DOCUMENT,
            actorId: $this->actorId($request),
            documentId: $model->id,
            documentExtractionId: $extractionId,
        ));

        $key = $transaction->getKey();

        return response()->json(['id' => is_scalar($key) ? (string) $key : ''], 201);
    }

    /**
     * The reading this transaction came from, if the request named one that belongs to this
     * document.
     *
     * Checked rather than trusted: an extraction id from another document would attach provenance
     * pointing at a reading of something else entirely, which is worse than no provenance at all.
     *
     * @param  array<string, mixed>  $validated
     */
    private function extractionId(array $validated, Document $document): ?string
    {
        $requested = $validated['document_extraction_id'] ?? null;

        if (! is_string($requested) || $requested === '') {
            return null;
        }

        $exists = DocumentExtraction::query()
            ->whereKey($requested)
            ->where('document_id', $document->id)
            ->exists();

        return $exists ? $requested : null;
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
