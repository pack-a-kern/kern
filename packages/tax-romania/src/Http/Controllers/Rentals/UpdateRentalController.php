<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Rentals;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\TaxRomania\Rental\Actions\UpdateRentalDetails;
use Kern\TaxRomania\Rental\DataTransferObjects\UpdateRentalDetailsInput;
use Kern\TaxRomania\Rental\Enums\PropertyType;
use Kern\TaxRomania\Rental\Enums\TenantType;
use Kern\TaxRomania\Rental\Exceptions\InvalidOwnershipPercentException;
use Kern\TaxRomania\Rental\Models\RentalContract;

/**
 * `PATCH /api/v1/subjects/{subject}/rentals/{rental}` — correct a rental's description.
 *
 * The rent and the dates are refused rather than ignored: a changed rent is an amendment from a
 * date (`POST …/rent`) and an ending is its own request (`POST …/end`), and silently dropping either
 * field would let a caller believe it had changed a figure it had not.
 */
final class UpdateRentalController
{
    public function __invoke(Request $request, string $subject, string $rental, UpdateRentalDetails $update): JsonResponse
    {
        // Tenant-scoped: another taxpayer's rental is not found rather than edited.
        $contract = RentalContract::query()->findOrFail($rental);

        $validated = $request->validate([
            'property_type' => ['sometimes', Rule::enum(PropertyType::class)],
            'address' => ['sometimes', 'string', 'max:255'],
            'ownership_percent' => ['sometimes', 'string', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'tenant_type' => ['sometimes', Rule::enum(TenantType::class)],
            'tenant_name' => ['sometimes', 'string', 'max:200'],
            'rent_minor' => ['prohibited'],
            'start_date' => ['prohibited'],
            'end_date' => ['prohibited'],
            'currency_code' => ['prohibited'],
            'payment_frequency' => ['prohibited'],
        ]);

        try {
            $update($contract, new UpdateRentalDetailsInput(
                propertyType: isset($validated['property_type']) && is_string($validated['property_type'])
                    ? PropertyType::from($validated['property_type'])
                    : null,
                address: self::optional($validated, 'address'),
                ownershipPercentDecimal: self::optional($validated, 'ownership_percent'),
                tenantType: isset($validated['tenant_type']) && is_string($validated['tenant_type'])
                    ? TenantType::from($validated['tenant_type'])
                    : null,
                tenantName: self::optional($validated, 'tenant_name'),
                actorId: self::actorId($request),
            ));
        } catch (InvalidOwnershipPercentException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'reason' => 'INVALID_OWNERSHIP_PERCENT'], 422);
        }

        return response()->json(['id' => $contract->id]);
    }

    /** @param array<string, mixed> $validated */
    private static function optional(array $validated, string $key): ?string
    {
        $value = $validated[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private static function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
