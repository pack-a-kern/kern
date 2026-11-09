<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\TaxRomania\Wallet\Actions\AddHolding;
use Kern\TaxRomania\Wallet\DataTransferObjects\AddHoldingInput;
use Kern\TaxRomania\Wallet\Enums\HeldAt;
use Kern\TaxRomania\Wallet\Exceptions\HoldingRefusedException;
use Kern\TaxRomania\Wallet\Models\InvestmentLot;

/**
 * `POST /api/v1/subjects/{subject}/wallet/holdings` — a purchase of a bond or a share, into an
 * investments account. Only what the person decided is accepted; the instrument's terms are its own and
 * are not fields here. Thin over `AddHolding`, which refuses an account or an instrument that cannot hold
 * it, and finds again a purchase already entered under the same broker reference.
 */
final class AddHoldingController
{
    public function __invoke(Request $request, AddHolding $add): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'uuid'],
            'instrument_id' => ['required', 'uuid'],
            'units' => ['required', 'string', 'regex:' . InvestmentLot::DECIMAL],
            'price_per_unit' => ['required', 'string', 'regex:' . InvestmentLot::DECIMAL],
            // Bought, or subscribed: a day that has come.
            'bought_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'fees_minor' => ['required', 'integer', 'min:0'],
            'held_at' => ['required', Rule::enum(HeldAt::class)],
            'external_ref' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $id = $request->user()?->getAuthIdentifier();

        try {
            $lot = $add(new AddHoldingInput(
                accountId: DepositFields::str($validated, 'account_id'),
                instrumentId: DepositFields::str($validated, 'instrument_id'),
                units: self::decimal($validated, 'units'),
                pricePerUnit: self::decimal($validated, 'price_per_unit'),
                boughtOn: DepositFields::str($validated, 'bought_on'),
                feesMinor: DepositFields::int($validated, 'fees_minor'),
                heldAt: HeldAt::from(DepositFields::str($validated, 'held_at')),
                externalRef: DepositFields::nullableString($validated, 'external_ref'),
                actorId: is_scalar($id) ? (string) $id : null,
            ));
        } catch (HoldingRefusedException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'reason' => $exception->reason->value], 422);
        }

        return response()->json(['id' => $lot->id], 201);
    }

    /**
     * A decimal the validator has already matched against `InvestmentLot::DECIMAL`.
     *
     * @param  array<string, mixed>  $validated
     * @return numeric-string
     */
    public static function decimal(array $validated, string $key): string
    {
        $value = DepositFields::str($validated, $key);
        assert(is_numeric($value));

        return $value;
    }
}
