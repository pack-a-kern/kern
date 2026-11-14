<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\DepositCsvImportTest;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Banks\Models\Bank;
use Kern\TaxRomania\Database\Factories\BankFactory;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * Term deposits added in bulk from a CSV.
 *
 * The property under test is that a bulk deposit is a form deposit: read by the same parser as a
 * statement, refused by the same rules as the form, opened by the same action — and that the file
 * itself is kept nowhere.
 */
beforeEach(function (): void {
    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );
});

/** @return array{0: User, 1: string} */
function owner(): array
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

    return [$user, $subject->id];
}

function bank(): Bank
{
    return BankFactory::new()->createOne(['name' => 'Banca Transilvania', 'bic' => 'BTRLRO22XXX']);
}

/** A file as a Romanian spreadsheet saves one: `;` between cells, `,` for the decimal point. */
function csv(string $contents, string $name = 'deposits.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function reading(UploadedFile $file, array $overrides = []): array
{
    return [
        'file' => $file,
        'separator' => ';',
        'date_format' => 'DD.MM.YYYY',
        'decimal_separator' => ',',
        ...$overrides,
    ];
}

/** @return array<string, string> */
function fullMapping(): array
{
    return [
        'starts_at' => 'Data',
        'principal' => 'Suma',
        'annual_rate' => 'Dobanda',
        'term_months' => 'Luni',
        'bank' => 'Banca',
        'currency' => 'Moneda',
    ];
}

/**
 * One deposit as the form sends it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function deposit(string $bankId, array $overrides = []): array
{
    return [
        'bank_id' => $bankId,
        'currency_code' => 'RON',
        'starts_at' => now('UTC')->toDateString(),
        'principal_minor' => 1_000_000,
        'term_months' => 6,
        'annual_rate' => '6.5',
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
        ...$overrides,
    ];
}

/**
 * Every table a deposit, a document or an import could leave a row in.
 *
 * @return array<string, int>
 */
function footprint(): array
{
    return [
        'accounts' => DB::table('financial_accounts')->where('kind', 'TERM_DEPOSIT')->count(),
        'terms' => DB::table('deposit_terms')->count(),
        'documents' => DB::table('documents')->count(),
        'imports' => DB::table('statement_imports')->count(),
    ];
}

it('reads the columns, then every line under the mapping, and stores nothing', function (): void {
    [$user, $subjectId] = owner();
    $bank = bank();
    $before = footprint();
    $file = "Data;Suma;Dobanda;Luni;Banca;Moneda\n01.12.2026;10.000,50;6,25 %;6;Banca Transilvania;ron\n15.12.2026;2.500;5,9;12;BTRL;EUR\n";

    $columns = $this->actingAs($user)
        ->post("/api/v1/subjects/{$subjectId}/wallet/deposits/csv", reading(csv($file)), ['Accept' => 'application/json'])
        ->assertOk();

    expect($columns->json('headers'))->toBe(['Data', 'Suma', 'Dobanda', 'Luni', 'Banca', 'Moneda'])
        ->and($columns->json('sample.0'))->toBe(['01.12.2026', '10.000,50', '6,25 %', '6', 'Banca Transilvania', 'ron'])
        // Nothing named yet, so nothing is read.
        ->and($columns->json('rows.0.values.starts_at'))->toBeNull();

    $rows = $this->actingAs($user)
        ->post("/api/v1/subjects/{$subjectId}/wallet/deposits/csv", reading(csv($file), ['mapping' => fullMapping()]), ['Accept' => 'application/json'])
        ->assertOk();

    expect($rows->json('rows.0.line'))->toBe(2)
        ->and($rows->json('rows.0.values.starts_at.value'))->toBe('2026-12-01')
        ->and($rows->json('rows.0.values.principal_minor.value'))->toBe(1_000_050)
        ->and($rows->json('rows.0.values.annual_rate.value'))->toBe('6.25')
        ->and($rows->json('rows.0.values.term_months.value'))->toBe(6)
        ->and($rows->json('rows.0.values.bank_id.value'))->toBe($bank->id)
        ->and($rows->json('rows.0.values.currency_code.value'))->toBe('RON')
        // By its code as well as its name.
        ->and($rows->json('rows.1.values.bank_id.value'))->toBe($bank->id)
        ->and($rows->json('rows.1.values.principal_minor.value'))->toBe(250_000);

    expect(footprint())->toBe($before);
});

it('marks a cell it cannot read beside the cell, instead of guessing', function (): void {
    [$user, $subjectId] = owner();
    bank();
    $file = "Data;Suma;Dobanda;Luni;Banca;Moneda\n31.02.2026;zece;6,5;sase;Banca Inventata;RON\n";

    $values = $this->actingAs($user)
        ->post("/api/v1/subjects/{$subjectId}/wallet/deposits/csv", reading(csv($file), ['mapping' => fullMapping()]), ['Accept' => 'application/json'])
        ->assertOk();

    expect($values->json('rows.0.values.starts_at'))->toBe(['value' => null, 'cell' => '31.02.2026'])
        ->and($values->json('rows.0.values.principal_minor'))->toBe(['value' => null, 'cell' => 'zece'])
        ->and($values->json('rows.0.values.term_months'))->toBe(['value' => null, 'cell' => 'sase'])
        ->and($values->json('rows.0.values.bank_id'))->toBe(['value' => null, 'cell' => 'Banca Inventata']);
});

it('refuses a file it was not made to read', function (string $name, string $contents, array $overrides, string $field): void {
    [$user, $subjectId] = owner();
    config(['wallet.deposit.import.max_rows' => 2, 'wallet.deposit.import.max_file_bytes' => 2048]);

    $this->actingAs($user)
        ->post("/api/v1/subjects/{$subjectId}/wallet/deposits/csv", reading(csv($contents, $name), $overrides), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'not a CSV' => ['deposits.xlsx', "Data;Suma\n01.12.2026;100\n", [], 'file'],
    // `ă` as Windows-1250 writes it, the encoding an older Romanian Excel saves in.
    'not UTF-8' => ['deposits.csv', "Banca\nBanca Comercial\xE3\n", [], 'file'],
    'only a header' => ['deposits.csv', "Data;Suma\n", [], 'file'],
    'more lines than allowed' => ['deposits.csv', "Data\n01.12.2026\n02.12.2026\n03.12.2026\n", [], 'file'],
    'larger than allowed' => ['deposits.csv', "Data\n" . str_repeat("01.12.2026\n", 300), [], 'file'],
    'a column the file does not have' => ['deposits.csv', "Data\n01.12.2026\n", ['mapping' => ['starts_at' => 'Inceput']], 'mapping.starts_at'],
    'a separator the reader does not offer' => ['deposits.csv', "Data\n01.12.2026\n", ['separator' => '|'], 'separator'],
]);

it('opens every deposit exactly as the form opens one: its schedule and its audit event included', function (): void {
    [$user, $subjectId] = owner();
    $bank = bank();

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/deposits", ['deposits' => [
            deposit($bank->id),
            deposit($bank->id, ['currency_code' => 'EUR', 'principal_minor' => 250_000]),
        ]])
        ->assertCreated()
        ->assertJson(['opened' => 2]);

    $accounts = DB::table('financial_accounts')->where('tax_subject_id', $subjectId)->where('kind', 'TERM_DEPOSIT')->pluck('id')->all();

    expect($accounts)->toHaveCount(2)
        ->and(DB::table('deposit_terms')->whereIn('account_id', $accounts)->pluck('bank_id')->unique()->all())->toBe([$bank->id])
        ->and(DB::table('deposit_interest_events')->whereIn('account_id', $accounts)->count())->toBeGreaterThanOrEqual(2)
        ->and(DB::table('audit_events')->whereIn('resource_id', $accounts)->where('event_type', 'WalletAccountOpened')->count())->toBe(2);

    // And named after the bank, as a deposit from the form is.
    $listed = collect((array) $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->json('accounts'))
        ->whereIn('id', $accounts)
        ->pluck('display_name')
        ->unique()
        ->all();

    expect(array_values($listed))->toBe(['Banca Transilvania']);
});

it('opens nothing when one deposit breaks a rule the form enforces, and says which', function (array $bad, string $field): void {
    [$user, $subjectId] = owner();
    $bank = bank();
    $closed = BankFactory::new()->createOne(['name' => 'Banca Inchisa', 'active' => false]);
    $bad = array_map(static fn (mixed $value): mixed => $value === 'CLOSED_BANK' ? $closed->id : $value, $bad);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/deposits", ['deposits' => [
            deposit($bank->id),
            deposit($bank->id, $bad),
        ]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(["deposits.1.{$field}"]);

    expect(DB::table('financial_accounts')->where('tax_subject_id', $subjectId)->where('kind', 'TERM_DEPOSIT')->count())->toBe(0);
})->with([
    'already matured' => [['starts_at' => '2019-01-01', 'term_months' => 6], 'term_months'],
    'a term longer than any offered' => [['term_months' => 85], 'term_months'],
    'a rate written with a comma' => [['annual_rate' => '6,5'], 'annual_rate'],
    'no principal' => [['principal_minor' => 0], 'principal_minor'],
    'a closed bank' => [['bank_id' => 'CLOSED_BANK'], 'bank_id'],
    'no currency' => [['currency_code' => null], 'currency_code'],
    'an unknown rate type' => [['rate_type' => 'WHATEVER'], 'rate_type'],
]);

it('refuses more deposits at once than the limit, and an empty list', function (): void {
    [$user, $subjectId] = owner();
    $bank = bank();
    config(['wallet.deposit.import.max_rows' => 1]);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/deposits", ['deposits' => [deposit($bank->id), deposit($bank->id)]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['deposits']);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/deposits", ['deposits' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['deposits']);
});

it('never reads for, or opens into, a taxpayer the person is not a member of', function (): void {
    [$user] = owner();
    [, $otherSubjectId] = owner();
    $bank = bank();

    $this->actingAs($user)
        ->post("/api/v1/subjects/{$otherSubjectId}/wallet/deposits/csv", reading(csv("Data\n01.12.2026\n")), ['Accept' => 'application/json'])
        ->assertForbidden();

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$otherSubjectId}/wallet/deposits", ['deposits' => [deposit($bank->id)]])
        ->assertForbidden();

    expect(DB::table('financial_accounts')->where('tax_subject_id', $otherSubjectId)->where('kind', 'TERM_DEPOSIT')->count())->toBe(0);
});

it("refuses to fund a deposit from another taxpayer's account", function (): void {
    [$user, $subjectId] = owner();
    [$other, $otherSubjectId] = owner();
    $bank = bank();

    $theirs = $this->actingAs($other)
        ->postJson("/api/v1/subjects/{$otherSubjectId}/wallet/accounts", ['kind' => 'OTHER', 'display_name' => 'Curent'])
        ->assertCreated()
        ->json('id');

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/deposits", ['deposits' => [deposit($bank->id, ['funding_account_id' => $theirs])]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['deposits.0.funding_account_id']);
});
