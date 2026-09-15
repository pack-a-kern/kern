<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Enums\TransactionImportState;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentExtraction;
use App\Domain\Documents\Queries\ResolveTransactionImportState;
use App\Domain\Rental\Actions\OpenRental;
use App\Domain\Rental\DataTransferObjects\OpenRentalInput;
use App\Domain\Rental\Enums\PropertyType;
use App\Domain\Rental\Enums\RentalPaymentFrequency;
use App\Domain\Rental\Enums\TenantType;
use App\Domain\Rental\Exceptions\InvalidOwnershipPercentException;
use App\Domain\Subjects\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/subjects/{subject}/rentals` — let a property, optionally from a contract document.
 *
 * ## From a document
 *
 * A contract becomes a rental the way a receipt becomes a transaction: only once a person has
 * accepted what was read from it, and only once. The rule is the same one the transaction import
 * uses — {@see ResolveTransactionImportState} — so a contract's review screen and this endpoint
 * cannot disagree about whether a rental may still be made from it. The figures themselves arrive in
 * the request: the reading pre-filled the form, and what the person saved is what is recorded.
 */
final class OpenRentalController extends Controller
{
    public function __invoke(
        Request $request,
        OpenRental $open,
        TenantContext $tenant,
        ResolveTransactionImportState $importState,
    ): JsonResponse {
        $validated = $request->validate([
            'property_type' => ['required', Rule::enum(PropertyType::class)],
            'address' => ['required', 'string', 'max:255'],
            // A decimal string, never a float — it multiplies into an income figure.
            'ownership_percent' => ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'tenant_type' => ['required', Rule::enum(TenantType::class)],
            'tenant_name' => ['required', 'string', 'max:200'],
            'start_date' => ['required', 'date'],
            // No "after today" here, unlike a deposit's maturity: a rental that has already ended is
            // a real contract whose years were owed, and recording one is how those years get counted.
            'end_date' => ['sometimes', 'nullable', 'date', 'after:start_date'],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'rent_minor' => ['required', 'integer', 'min:1'],
            'payment_frequency' => ['required', Rule::enum(RentalPaymentFrequency::class)],
            'payment_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
            'document_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $documentId = is_string($validated['document_id'] ?? null) ? $validated['document_id'] : null;
        $extractionId = null;

        if ($documentId !== null) {
            // Tenant-scoped: another taxpayer's document is not found rather than linked.
            $document = Document::query()->findOrFail($documentId);

            if ($document->type !== DocumentType::CONTRACT) {
                return response()->json(['message' => 'Only a contract can become a rental.', 'reason' => 'NOT_A_CONTRACT'], 409);
            }

            $state = $importState($document);

            if ($state !== TransactionImportState::AVAILABLE) {
                return response()->json([
                    'message' => 'This contract cannot become a rental in its current state.',
                    'reason' => $state->value,
                ], 409);
            }

            $accepted = DocumentExtraction::query()
                ->where('document_id', $document->id)
                ->whereIn('status', ResolveTransactionImportState::ACCEPTED_STATUSES)
                ->orderByDesc('id')
                ->value('id');
            $extractionId = is_string($accepted) ? $accepted : null;
        }

        try {
            $contract = $open(new OpenRentalInput(
                taxSubjectId: $tenant->currentSubjectId(),
                propertyType: PropertyType::from(self::str($validated, 'property_type')),
                address: self::str($validated, 'address'),
                ownershipPercentDecimal: self::str($validated, 'ownership_percent'),
                tenantType: TenantType::from(self::str($validated, 'tenant_type')),
                tenantName: self::str($validated, 'tenant_name'),
                startDate: self::str($validated, 'start_date'),
                endDate: is_string($validated['end_date'] ?? null) ? $validated['end_date'] : null,
                currencyCode: self::str($validated, 'currency_code'),
                rentMinor: (int) self::str($validated, 'rent_minor'),
                paymentFrequency: RentalPaymentFrequency::from(self::str($validated, 'payment_frequency')),
                paymentDay: is_numeric($validated['payment_day'] ?? null) ? (int) $validated['payment_day'] : null,
                sourceDocumentId: $documentId,
                extractionId: $extractionId,
                actorId: self::actorId($request),
            ));
        } catch (InvalidOwnershipPercentException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'reason' => 'INVALID_OWNERSHIP_PERCENT'], 422);
        }

        return response()->json(['id' => $contract->id], 201);
    }

    /** @param array<string, mixed> $validated */
    private static function str(array $validated, string $key): string
    {
        $value = $validated[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private static function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
