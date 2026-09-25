<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Wallet\RecordFinancialTransactionTest;

use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Wallet\Actions\OpenFinancialAccount;
use App\Domain\Wallet\Actions\RecordFinancialTransaction;
use App\Domain\Wallet\DataTransferObjects\OpenFinancialAccountInput;
use App\Domain\Wallet\DataTransferObjects\RecordFinancialTransactionInput;
use App\Domain\Wallet\DataTransferObjects\TransactionSplitInput;
use App\Domain\Wallet\Enums\AccountKind;
use App\Domain\Wallet\Enums\TransactionClassificationStatus;
use App\Domain\Wallet\Exceptions\TransactionSplitMismatchException;
use App\Domain\Wallet\Models\FinancialAccount;
use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantContext;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
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
