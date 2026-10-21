<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\RecordFinancialTransactionTest;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Actions\OpenFinancialAccount;
use Kern\TaxRomania\Wallet\Actions\RecordFinancialTransaction;
use Kern\TaxRomania\Wallet\DataTransferObjects\OpenFinancialAccountInput;
use Kern\TaxRomania\Wallet\DataTransferObjects\RecordFinancialTransactionInput;
use Kern\TaxRomania\Wallet\DataTransferObjects\TransactionSplitInput;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\TransactionClassificationStatus;
use Kern\TaxRomania\Wallet\Exceptions\TransactionSplitMismatchException;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

/**
 * @return array{0: TaxSubject, 1: FinancialAccount}
 */
function activatedAccount(): array
{
    $subject = TaxSubject::factory()->create();
    $user = User::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);

    $account = app(OpenFinancialAccount::class)(new OpenFinancialAccountInput(
        taxSubjectId: $subject->id,
        kind: AccountKind::GENERAL,
        displayName: 'Test account',
        currencyCode: 'RON',
    ));

    return [$subject, $account];
}

it('marks a transaction with no splits as UNCLASSIFIED', function (): void {
    [$subject, $account] = activatedAccount();

    $transaction = app(RecordFinancialTransaction::class)(new RecordFinancialTransactionInput(
        taxSubjectId: $subject->id,
        accountId: $account->id,
        bookedAt: '2026-01-10',
        amountMinor: -1000,
        currencyCode: 'RON',
        description: 'Groceries',
        counterparty: 'Local Shop',
    ));

    expect($transaction->classification_status)->toBe(TransactionClassificationStatus::UNCLASSIFIED);
});

it('marks a transaction with splits summing to its amount as CLASSIFIED and persists the splits', function (): void {
    [$subject, $account] = activatedAccount();

    $transaction = app(RecordFinancialTransaction::class)(new RecordFinancialTransactionInput(
        taxSubjectId: $subject->id,
        accountId: $account->id,
        bookedAt: '2026-01-10',
        amountMinor: -1000,
        currencyCode: 'RON',
        description: 'Mixed shop',
        counterparty: 'Local Shop',
        splits: [
            new TransactionSplitInput(-700, 'GROCERIES'),
            new TransactionSplitInput(-300, 'HOUSEHOLD'),
        ],
    ));

    expect($transaction->classification_status)->toBe(TransactionClassificationStatus::CLASSIFIED)
        ->and($transaction->splits()->count())->toBe(2)
        // Postgres returns a bigint SUM() as a numeric string via PDO, not a PHP int.
        ->and((int) $transaction->splits()->sum('amount_minor'))->toBe(-1000);
});

it('refuses an account belonging to another subject, whoever the caller is', function (): void {
    // The backstop under every controller: the tenant-scoped lookup finds nothing, and nothing
    // found must refuse rather than let the row through.
    [, $foreignAccount] = activatedAccount();
    [$subject] = activatedAccount();

    expect(fn () => app(RecordFinancialTransaction::class)(new RecordFinancialTransactionInput(
        taxSubjectId: $subject->id,
        accountId: $foreignAccount->id,
        bookedAt: '2026-01-10',
        amountMinor: 500000,
        currencyCode: 'RON',
        description: 'Not yours',
        counterparty: '',
    )))->toThrow(ModelNotFoundException::class);

    expect(DB::table('financial_transactions')->where('account_id', $foreignAccount->id)->count())->toBe(0);
});

it('refuses to persist splits that do not sum to the transaction amount', function (): void {
    [$subject, $account] = activatedAccount();

    app(RecordFinancialTransaction::class)(new RecordFinancialTransactionInput(
        taxSubjectId: $subject->id,
        accountId: $account->id,
        bookedAt: '2026-01-10',
        amountMinor: -1000,
        currencyCode: 'RON',
        description: 'Mismatched split',
        counterparty: 'Local Shop',
        splits: [
            new TransactionSplitInput(-700, 'GROCERIES'),
            new TransactionSplitInput(-200, 'HOUSEHOLD'),
        ],
    ));
})->throws(TransactionSplitMismatchException::class);
