<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Wallet\RawDatabasePlaintextTest;

use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Subjects\Tenancy\TenantContext;
use App\Domain\Wallet\Actions\OpenFinancialAccount;
use App\Domain\Wallet\Actions\RecordFinancialTransaction;
use App\Domain\Wallet\DataTransferObjects\OpenFinancialAccountInput;
use App\Domain\Wallet\DataTransferObjects\RecordFinancialTransactionInput;
use App\Domain\Wallet\Enums\AccountKind;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * `BLUEPRINT.md` §4.3 lists IBAN, account number, and transaction description as mandatory to
 * encrypt. A raw dump of `financial_accounts`/`financial_transactions` must not contain any of
 * the plaintext values that went in — the same style of proof
 * `tests/Feature/Domain/Subjects/RawDatabasePlaintextTest.php` establishes for identity tables.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

/**
 * Creates a subject, an owning user/membership, and activates `TenantContext` for it — every
 * wallet model's `creating` hook (`App\Domain\Subjects\Tenancy\ScopedToTaxSubject`) needs an
 * authorized subject bound before a row can be created.
 */
function activatedSubject(): TaxSubject
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

    $context = new TenantContext();
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);

    return $subject;
}

it('never stores an account\'s plaintext display name or IBAN anywhere in the raw row', function (): void {
    $subject = activatedSubject();

    $plaintextDisplayName = 'Recognisable Current Account';
    $plaintextIban = 'RO49AAAA1B31007593840000';

    $account = app(OpenFinancialAccount::class)(new OpenFinancialAccountInput(
        taxSubjectId: $subject->id,
        kind: AccountKind::BANK_CURRENT,
        displayName: $plaintextDisplayName,
        currencyCode: 'RON',
        iban: $plaintextIban,
    ));

    /** @var object{display_name_ciphertext: string, iban_ciphertext: string}|null $raw */
    $raw = DB::table('financial_accounts')->where('id', $account->id)->first();
    expect($raw)->not->toBeNull();
    assert($raw !== null);

    foreach ([$raw->display_name_ciphertext, $raw->iban_ciphertext] as $column) {
        expect($column)->not->toContain($plaintextDisplayName)
            ->and($column)->not->toContain($plaintextIban)
            ->and((string) base64_decode($column, true))->not->toContain($plaintextDisplayName)
            ->and((string) base64_decode($column, true))->not->toContain($plaintextIban);
    }
});

it('never stores a transaction\'s plaintext description, counterparty, or external id anywhere in the raw row', function (): void {
    $subject = activatedSubject();

    $account = app(OpenFinancialAccount::class)(new OpenFinancialAccountInput(
        taxSubjectId: $subject->id,
        kind: AccountKind::BANK_CURRENT,
        displayName: 'Account for transaction test',
        currencyCode: 'RON',
    ));

    $plaintextDescription = 'Payment for recognisable consulting invoice #4471';
    $plaintextCounterparty = 'Recognisable Consulting SRL';
    $plaintextExternalId = 'BANKREF-RECOGNISABLE-998877';

    $transaction = app(RecordFinancialTransaction::class)(new RecordFinancialTransactionInput(
        taxSubjectId: $subject->id,
        accountId: $account->id,
        bookedAt: '2026-01-10',
        amountMinor: -50_000,
        currencyCode: 'RON',
        description: $plaintextDescription,
        counterparty: $plaintextCounterparty,
        externalId: $plaintextExternalId,
    ));

    /**
     * @var object{
     *     description_ciphertext: string, counterparty_ciphertext: string,
     *     external_id_ciphertext: string,
     * }|null $raw
     */
    $raw = DB::table('financial_transactions')->where('id', $transaction->id)->first();
    expect($raw)->not->toBeNull();
    assert($raw !== null);

    $plaintexts = [$plaintextDescription, $plaintextCounterparty, $plaintextExternalId];
    $columns = [$raw->description_ciphertext, $raw->counterparty_ciphertext, $raw->external_id_ciphertext];

    foreach ($columns as $column) {
        foreach ($plaintexts as $plaintext) {
            expect($column)->not->toContain($plaintext)
                ->and((string) base64_decode($column, true))->not->toContain($plaintext);
        }
    }
});
