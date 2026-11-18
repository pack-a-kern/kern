<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\Banks\Queries\BankDirectory;
use Kern\TaxRomania\Wallet\Queries\BankActivity;

/**
 * `GET .../wallet/banks/activity` — the banks this taxpayer's transactions name, as a rundown.
 *
 * A sibling of `wallet/banks`, which is the catalogue a deposit form picks from; this is the subset
 * that actually appears in the ledger, with the counts and dates that make it a report.
 *
 * The ledger query returns bank *ids*, and this layer turns them into names, codes and logos through
 * {@see BankDirectory} — the HTTP layer is where `Wallet` and `Banks` are allowed to meet, so the
 * query stays a query over the ledger's own columns. The subject comes from the tenant context the
 * route binds, never from a parameter, so there is nothing to validate here.
 */
final class ShowBankActivityController
{
    public function __invoke(BankActivity $activity, BankDirectory $banks): JsonResponse
    {
        $rundown = $activity->rundown();

        $options = $banks->findMany(array_values(array_filter(
            array_map(static fn (array $row): ?string => $row['bank_id'], $rundown['banks']),
            static fn (?string $bankId): bool => $bankId !== null,
        )));

        $named = array_map(static function (array $row) use ($options): array {
            $option = $row['bank_id'] === null ? null : ($options[$row['bank_id']] ?? null);

            return [
                ...$row,
                'name' => $option?->name,
                'code' => $option?->code,
                // A bank the register no longer lists is still greyed rather than hidden: a statement
                // names whichever bank existed then. An id with no option — reference data not yet
                // imported — reads as active, since the row is there because transactions carry it.
                'active' => $option === null ? true : $option->active,
                'logo_url' => $option?->logoUrl,
            ];
        }, $rundown['banks']);

        return response()->json([
            'banks' => $named,
            'totals' => [
                ...$rundown['totals'],
                'bank_count' => count(array_filter($named, static fn (array $row): bool => $row['bank_id'] !== null)),
            ],
        ]);
    }
}
