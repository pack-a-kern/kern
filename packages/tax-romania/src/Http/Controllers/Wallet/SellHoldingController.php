<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\Wallet\Actions\SellHolding;
use Kern\TaxRomania\Wallet\DataTransferObjects\SellHoldingInput;
use Kern\TaxRomania\Wallet\Exceptions\HoldingRefusedException;
use Kern\TaxRomania\Wallet\Models\InvestmentLot;

/**
 * `POST /api/v1/subjects/{subject}/wallet/holdings/sales` — units of one instrument sold from one account
 * before maturity: the date, the units, the price per unit and the fees. Nothing else: the amount received
 * and the result are computed (`SellHolding`), and a Tezaur bond is refused with its reason.
 */
final class SellHoldingController
{
    public function __invoke(Request $request, SellHolding $sell): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'uuid'],
            'instrument_id' => ['required', 'uuid'],
            'units' => ['required', 'string', 'regex:' . InvestmentLot::DECIMAL],
            'price_per_unit' => ['required', 'string', 'regex:' . InvestmentLot::DECIMAL],
            'sold_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'fees_minor' => ['required', 'integer', 'min:0'],
        ]);

        $id = $request->user()?->getAuthIdentifier();

        try {
            $sold = $sell(new SellHoldingInput(
                accountId: DepositFields::str($validated, 'account_id'),
                instrumentId: DepositFields::str($validated, 'instrument_id'),
                units: AddHoldingController::decimal($validated, 'units'),
                pricePerUnit: AddHoldingController::decimal($validated, 'price_per_unit'),
                soldOn: DepositFields::str($validated, 'sold_on'),
                feesMinor: DepositFields::int($validated, 'fees_minor'),
                actorId: is_scalar($id) ? (string) $id : null,
            ));
        } catch (HoldingRefusedException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'reason' => $exception->reason->value], 422);
        }

        return response()->json(['ids' => array_map(static fn (InvestmentLot $lot): string => $lot->id, $sold)], 201);
    }
}
