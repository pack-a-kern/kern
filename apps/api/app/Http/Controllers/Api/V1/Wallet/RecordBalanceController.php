<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Subjects\Tenancy\TenantContext;
use App\Domain\Wallet\Actions\RecordAccountBalanceSnapshot;
use App\Domain\Wallet\DataTransferObjects\RecordAccountBalanceSnapshotInput;
use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Http\Controllers\Controller;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/subjects/{subject}/wallet/accounts/{account}/balance` — record an observed balance.
 *
 * A *snapshot*, not an update: `account_balance_snapshots` is append-only in spirit, and an account
 * has no balance column at all. That shape is deliberate — a balance is something observed on a
 * date, from a source, with or without verification, and a tax figure derived from it has to be
 * able to say which observation it used. Overwriting a single column would destroy exactly that.
 *
 * So recording today's balance never invalidates yesterday's, and the screen shows the most recent
 * one alongside the date it was taken.
 *
 * `source` is fixed to `MANUAL` here because that is what this endpoint is: a person typing a
 * number. An importer will pass `IMPORT`, and a bank connection `BANK_SYNC`, through the same
 * action — the distinction matters when reconciling, so it is never inferred.
 */
final class RecordBalanceController extends Controller
{
    public function __invoke(
        Request $request,
        string $subject,
        string $account,
        RecordAccountBalanceSnapshot $record,
        TenantContext $tenant,
    ): JsonResponse {
        // Resolved through the tenant-scoped model, so an account belonging to another subject is
        // a 404 here rather than a foreign key the action would happily accept.
        $financialAccount = FinancialAccount::query()->findOrFail($account);

        $validated = $request->validate([
            'balance_minor' => ['required', 'integer'],
            'as_of' => ['required', 'date'],
            // Whether a human confirmed this against the bank. Not a formality: an unverified
            // manual figure and a statement-confirmed one carry different weight when a return is
            // being reconciled, and the screen shows which is which.
            'is_verified' => ['sometimes', 'boolean'],
        ]);

        $key = $financialAccount->getKey();

        $record(new RecordAccountBalanceSnapshotInput(
            taxSubjectId: $tenant->currentSubjectId(),
            accountId: is_scalar($key) ? (string) $key : '',
            // Signed: an overdraft is a real balance, and rejecting it would force a wrong number.
            balanceMinor: (int) $validated['balance_minor'],
            asOf: new DateTimeImmutable((string) $validated['as_of']),
            source: RecordSource::MANUAL,
            isVerified: $request->boolean('is_verified'),
        ));

        return response()->json(['recorded' => true], 201);
    }
}
