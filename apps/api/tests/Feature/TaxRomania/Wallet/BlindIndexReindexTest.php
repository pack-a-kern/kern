<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\BlindIndexReindexTest;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use Kern\Platform\Crypto\BlindIndex\Normalizer;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantContext;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\StatementImport\Enums\StatementImportStatus;
use Kern\TaxRomania\StatementImport\Models\StatementImport;
use Kern\TaxRomania\StatementImport\Models\StatementImportRow;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\MoneyDirection;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Models\Counterparty;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use RuntimeException;

/**
 * Rotating the blind-index key, and the job that makes the rotation true.
 *
 * A blind index is `HMAC-SHA-256(key, value)`, so a new key means every stored index is a hash under
 * a key nothing searches with any more. The row is not gone — it is unfindable, which is worse,
 * because nothing reports it. Each test here rotates the key, shows the lookup failing, runs
 * `kern:keys:reindex-blind-indexes`, and shows it working again.
 *
 * The three cases are the three shapes a blind index takes in this schema: one derived from a single
 * encrypted column (an email), one on a table that used to hold no copy of its value at all (a
 * counterparty rule), and one computed per file rather than per row (an import fingerprint).
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();
});

/**
 * Replaces the hasher with one holding both key versions, the newer of them current.
 *
 * Both, because that is what a real rotation looks like mid-flight: `CRYPTO_BLIND_INDEX_KEYS` keeps
 * the outgoing key until every index has been recomputed, which is exactly what the command decides.
 */
function rotateBlindIndexKey(): void
{
    $provider = new ConfigBlindIndexKeyProvider(
        json_encode([
            '1' => base64_encode(random_bytes(32)),
            '2' => base64_encode(random_bytes(32)),
        ], JSON_THROW_ON_ERROR),
        2,
    );

    app()->instance(BlindIndexHasher::class, new BlindIndexHasher($provider));
}

/** @return array{0: User, 1: TaxSubject} */
function subjectWithOwner(): array
{
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    // The models below are tenant-scoped, so something has to say which taxpayer is being acted for.
    // A test creating them directly says it here, the way a request says it through the route.
    app(TenantContext::class)->authorizeAndActivate((string) $user->id, (string) $subject->id);

    return [$user, $subject];
}

it('finds a user by email again once the indexes are rebuilt', function (): void {
    $user = User::createWithEmail('ana.ionescu@example.test');

    expect(User::query()->whereEmail('ana.ionescu@example.test')->first()?->id)->toBe($user->id);

    rotateBlindIndexKey();

    // The account is still there and still decrypts. What has gone is the ability to look it up,
    // which for a login is the same as the account not existing.
    expect(User::query()->whereEmail('ana.ionescu@example.test')->first())->toBeNull()
        ->and(User::query()->whereKey($user->id)->firstOrFail()->email_ciphertext)->toBe('ana.ionescu@example.test');

    Artisan::call('kern:keys:reindex-blind-indexes');

    expect(User::query()->whereEmail('ana.ionescu@example.test')->first()?->id)->toBe($user->id);
});

it('finds a counterparty rule again once the indexes are rebuilt', function (): void {
    [, $subject] = subjectWithOwner();

    $rule = new Counterparty([
        'tax_subject_id' => $subject->id,
        'counterparty_ciphertext' => 'LIDL DISCOUNT SRL',
        'counterparty_blind_index' => app(BlindIndexHasher::class)->compute(Normalizer::text('LIDL DISCOUNT SRL')),
        'direction' => MoneyDirection::OUT,
        'category_code' => 'GROCERIES',
        'source' => 'PERSON',
    ]);
    $rule->save();

    rotateBlindIndexKey();

    $searched = fn (): ?Counterparty => Counterparty::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('counterparty_blind_index', app(BlindIndexHasher::class)->compute(Normalizer::text('LIDL DISCOUNT SRL')))
        ->first();

    expect($searched())->toBeNull();

    Artisan::call('kern:keys:reindex-blind-indexes');

    // Recomputable only because the rule carries the name it indexes. Before that column existed this
    // row held a hash and nothing else, and this assertion could not have been made to pass at all.
    expect($searched()?->id)->toBe($rule->id);
});

it('rebuilds an import fingerprint in both places it is stored', function (): void {
    [, $subject] = subjectWithOwner();

    $account = new FinancialAccount(['tax_subject_id' => $subject->id]);
    $account->kind = AccountKind::OTHER;
    $account->currency_code = 'RON';
    $account->save();
    $account->display_name_ciphertext = 'Cont curent';
    $account->save();

    $import = new StatementImport(['tax_subject_id' => $subject->id]);
    $import->account_id = $account->id;
    $import->status = StatementImportStatus::IMPORTED;
    $import->row_count = 1;
    $import->duplicate_count = 0;
    $import->excluded_count = 0;
    $import->imported_count = 1;
    $import->lines_read = 1;
    $import->lines_folded = 0;
    $import->lines_skipped = 0;
    $import->save();

    $transaction = new FinancialTransaction([
        'tax_subject_id' => $subject->id,
        'account_id' => $account->id,
        'booked_at' => '2026-03-03',
        'amount_minor' => -4_500,
        'currency_code' => 'RON',
        'description_ciphertext' => 'PLATA CARD',
        'counterparty_ciphertext' => 'LIDL',
        'source' => RecordSource::IMPORT,
        'import_id' => $import->id,
    ]);
    $transaction->save();

    $row = new StatementImportRow(['tax_subject_id' => $subject->id]);
    $row->import_id = $import->id;
    $row->row_number = 1;
    $row->booked_at = Carbon::parse('2026-03-03');
    $row->amount_minor = -4_500;
    $row->currency_code = 'RON';
    $row->transaction_id = $transaction->id;
    $row->included = true;
    $row->save();

    $row->description_ciphertext = 'PLATA CARD';
    $row->counterparty_ciphertext = 'LIDL';
    $row->save();

    // The fingerprint as the import itself would have computed it, under the key in force now.
    $fingerprint = fingerprintFor($import, $row);

    $row->fingerprint = $fingerprint;
    $row->saveQuietly();
    FinancialTransaction::query()->whereKey($transaction->id)
        ->update(['external_id_blind_index' => $fingerprint]);

    rotateBlindIndexKey();

    Artisan::call('kern:keys:reindex-blind-indexes');

    $rebuilt = fingerprintFor($import, $row->fresh() ?? throw new RuntimeException('Row gone.'));

    expect($rebuilt)->not->toBe($fingerprint)
        ->and(storedValue('statement_import_rows', (string) $row->id, 'fingerprint'))->toBe($rebuilt)
        // Both places, because the ledger's uniqueness on this column is what refuses a second import
        // of the same file — comparing a new fingerprint against an old one would let it through.
        ->and(storedValue('financial_transactions', (string) $transaction->id, 'external_id_blind_index'))->toBe($rebuilt);
});

function storedValue(string $table, string $id, string $column): string
{
    $value = DB::table($table)->where('id', $id)->value($column);

    return is_string($value) ? $value : throw new RuntimeException("No {$column} on {$table}.{$id}.");
}

function fingerprintFor(StatementImport $import, StatementImportRow $row): string
{
    $fingerprints = app(\Kern\TaxRomania\StatementImport\Services\RowFingerprint::class)->forRows(
        [[
            'booked_at' => $row->booked_at?->format('Y-m-d'),
            'amount_minor' => (int) $row->amount_minor,
            'currency_code' => $row->currency_code,
            'text' => ((string) $row->description_ciphertext) . ' ' . ((string) $row->counterparty_ciphertext),
            'reference' => (string) $row->reference_ciphertext,
        ]],
        (string) $import->account_id,
        $import->bank_id,
    );

    return $fingerprints[0] ?? throw new RuntimeException('No fingerprint computed.');
}
