<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Wallet\Actions\RecordFinancialTransaction;
use App\Domain\Wallet\DataTransferObjects\RecordFinancialTransactionInput;
use App\Domain\Wallet\DataTransferObjects\TransactionSplitInput;
use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Exceptions\CategoryDirectionException;
use App\Domain\Wallet\Services\TaxCategoryCatalogue;
use App\Http\Controllers\Api\V1\Wallet\Concerns\RespondsToCategoryDirection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\Documents\Enums\DocumentType;
use Kern\Documents\Enums\TransactionImportState;
use Kern\Documents\Models\Document;
use Kern\Documents\Models\DocumentExtraction;
use Kern\Documents\Queries\ResolveTransactionImportState;
use Kern\Platform\Tenancy\TenantContext;

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
 *
 * ## What it refuses
 *
 * Accepting first is not a suggestion the screen makes, it is a rule this endpoint holds: a reading
 * still sitting as a proposal cannot become money, and a document that already produced its
 * transactions cannot produce them a second time. Both are checked here rather than only in the
 * client, because the button being hidden is a courtesy and this is the boundary — see
 * {@see TransactionImportState}.
 */
final class CreateTransactionFromDocumentController
{
    use RespondsToCategoryDirection;

    public function __invoke(
        Request $request,
        string $subject,
        string $document,
        RecordFinancialTransaction $record,
        TenantContext $tenant,
        ResolveTransactionImportState $importState,
        TaxCategoryCatalogue $categories,
    ): JsonResponse {
        // Tenant-scoped: another subject's document is not found rather than used as evidence.
        $model = Document::query()->findOrFail($document);

        $state = $importState($model);

        if ($state !== TransactionImportState::AVAILABLE) {
            // 409 rather than 403: nothing is wrong with who is asking, only with when. The reason
            // is a closed enum the client already understands, so the screen can say which of the
            // two it was without this endpoint writing a sentence for it.
            return response()->json([
                'message' => 'This document cannot become a transaction in its current state.',
                'reason' => $state->value,
            ], 409);
        }

        // A rental contract becomes a rental, not a transaction. Its rent is owed every period for as
        // long as it runs, and one transaction for one month's figure would be neither the income the
        // contract produces nor money that actually moved.
        if ($model->type === DocumentType::CONTRACT) {
            return response()->json([
                'message' => 'A contract becomes a rental, not a transaction.',
                'reason' => 'CONTRACT_DOCUMENT',
            ], 409);
        }

        $validated = $request->validate([
            'account_id' => ['required', 'uuid'],
            // Signed minor units, so the direction is the sign — a receipt is negative, a refund or
            // an incoming invoice positive. A separate direction field would let the two disagree.
            'amount_minor' => ['required', 'integer', 'not_in:0'],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'booked_at' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'counterparty' => ['sometimes', 'nullable', 'string', 'max:200'],
            'document_extraction_id' => ['sometimes', 'nullable', 'uuid'],
            // What this transaction is for. Through the same catalogue the other wallet endpoints
            // use, and the extraction prompt offers the model the same file — so a category it
            // proposed can always be accepted.
            'category' => ['sometimes', 'nullable', Rule::in($categories->codes())],
        ]);

        $extractionId = $this->extractionId($validated, $model);

        try {
            $transaction = $record(new RecordFinancialTransactionInput(
                taxSubjectId: $tenant->currentTenantId(),
                accountId: (string) $validated['account_id'],
                bookedAt: (string) $validated['booked_at'],
                amountMinor: (int) $validated['amount_minor'],
                currencyCode: (string) $validated['currency_code'],
                description: (string) $validated['description'],
                counterparty: is_string($validated['counterparty'] ?? null) ? $validated['counterparty'] : '',
                // Not MANUAL: "someone typed this in" and "this came from a document still in the
                // object store, with its hash and its scan record" are different provenance.
                source: RecordSource::DOCUMENT,
                actorId: $this->actorId($request),
                documentId: $model->id,
                documentExtractionId: $extractionId,
                splits: $this->splitsFor($validated),
            ));
        } catch (CategoryDirectionException $exception) {
            return $this->categoryDirection($exception);
        }

        $key = $transaction->getKey();

        return response()->json(['id' => is_scalar($key) ? (string) $key : ''], 201);
    }

    /**
     * One split covering the whole amount, when a category was chosen.
     *
     * One rather than several: this screen records a single payment for a single purpose, and a
     * transaction split across two categories is a different gesture with a different form. The
     * amount has to match the transaction's exactly or the action refuses it, so it is taken from
     * the same validated value rather than recomputed.
     *
     * Empty when no category was chosen, which leaves the transaction `UNCLASSIFIED` — the honest
     * state for a figure nobody has said anything about yet.
     *
     * @param  array<string, mixed>  $validated
     * @return list<TransactionSplitInput>
     */
    private function splitsFor(array $validated): array
    {
        $category = $validated['category'] ?? null;

        if (! is_string($category) || $category === '') {
            return [];
        }

        $amount = $validated['amount_minor'] ?? null;

        return [new TransactionSplitInput(
            amountMinor: is_numeric($amount) ? (int) $amount : 0,
            categoryCode: $category,
        )];
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
