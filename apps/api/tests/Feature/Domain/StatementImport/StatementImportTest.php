<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\StatementImport\StatementImportTest;

use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\StatementImport\DataTransferObjects\ParsedStatementFile;
use App\Domain\StatementImport\DataTransferObjects\StatementColumn;
use App\Domain\StatementImport\Enums\StatementFormat;
use App\Domain\StatementImport\Enums\StatementImportStatus;
use App\Domain\StatementImport\Gateways\StatementParseGateway;
use App\Domain\StatementImport\Jobs\ProposeRowCategories;
use App\Domain\StatementImport\Jobs\ReadStatementFile;
use App\Domain\StatementImport\Models\StatementImport;
use App\Domain\StatementImport\Models\StatementImportRow;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Subjects\Tenancy\TaxSubjectScope;
use App\Domain\Wallet\Enums\AccountKind;
use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Support\KeyManagement\SubjectDekService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use ReflectionProperty;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;
use Tests\TestCase;

/**
 * Reading a bank statement into transactions, end to end.
 *
 * The reader itself is faked: it is a service on another network, and what this suite is about is
 * everything around it — the mapping a person confirms, the duplicate that is caught before it is
 * written, the transactions that come out with their categories, and the undo that takes them all
 * back out again.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );

    $this->app->instance(StatementParseGateway::class, new FakeParseGateway());
});

/**
 * A statement, as the reader hands it back.
 *
 * Four columns and three rows in the shape a Romanian export actually has: dates written
 * `DD.MM.YYYY`, amounts `1.234,56`, and money out in its own column.
 */
final class FakeParseGateway implements StatementParseGateway
{
    /** @var list<list<string>> */
    public array $rows = [
        ['05.03.2026', 'PLATA CARD KAUFLAND', '123,45', ''],
        ['06.03.2026', 'SALARIU MARTIE', '', '7.500,00'],
        ['07.03.2026', 'PLATA CARD KAUFLAND', '123,45', ''],
    ];

    public function parse(string $contents, ?string $sheet = null): ParsedStatementFile
    {
        return new ParsedStatementFile(
            format: StatementFormat::CSV,
            columns: [
                new StatementColumn(0, 'Data', 'date DD.MM.YYYY', 1.0),
                new StatementColumn(1, 'Detalii', 'text ~20 chars', 1.0),
                new StatementColumn(2, 'Debit', 'number 1.234,56', 0.67),
                new StatementColumn(3, 'Credit', 'number 1.234,56', 0.33),
            ],
            rows: $this->rows,
            headerRow: 0,
        );
    }
}

/** @return array{0: User, 1: string, 2: string} the user, the subject, and the account to import into */
function importer(): array
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

    $account = FinancialAccount::create([
        'tax_subject_id' => $subject->id,
        'kind' => AccountKind::GENERAL,
        'display_name_ciphertext' => null,
        'currency_code' => 'RON',
        'source' => RecordSource::MANUAL,
    ]);

    return [$user, $subject->id, $account->id];
}

/** The file a person uploads. Its bytes are never read here — the gateway is faked. */
function statementFile(string $name = 'extras.csv'): UploadedFile
{
    /*
     * Two exports differ in their bytes — otherwise the second upload is refused as the same
     * document — but both are still a table: the upload sniffer recognises a CSV by its first two
     * lines carrying the same number of delimiters, so the difference goes in a row rather than in a
     * comment line above the header.
     */
    return UploadedFile::fake()->createWithContent(
        $name,
        "Data;Detalii;Debit;Credit\r\n05.03.2026;PLATA CARD KAUFLAND;123,45;\r\n06.03.2026;EXPORT {$name};1,00;\r\n",
    );
}

/**
 * Uploads a statement and runs the read job, leaving the import waiting for its columns.
 *
 * `$name` distinguishes one export from another: the same bytes uploaded twice are refused as a
 * duplicate *document*, so a second import of the same period is a different file — which is exactly
 * what a person does when their bank re-exports an overlapping month.
 */
function startImport(TestCase $case, User $user, string $subjectId, string $accountId, string $name = 'extras.csv'): StatementImport
{
    /** @var string $importId */
    $importId = $case->actingAs($user)->post("/api/v1/subjects/{$subjectId}/wallet/imports", [
        'file' => statementFile($name),
        'account_id' => $accountId,
    ])->assertStatus(202)->json('id');

    // The scan is what the job waits for; in a test the document is promoted directly, which is the
    // state the job would find a moment later in real life.
    $import = StatementImport::withoutGlobalScope(TaxSubjectScope::class)->findOrFail($importId);
    Document::withoutGlobalScope(TaxSubjectScope::class)
        ->whereKey($import->document_id)
        ->update(['status' => DocumentStatus::READY->value]);

    app(ReadStatementFile::class, ['importId' => $importId])->handle(
        app(\App\Support\ObjectStore\EncryptedObjectStore::class),
        app(StatementParseGateway::class),
        app(\App\Domain\StatementImport\Services\SuggestColumnMapping::class),
    );

    return $import->refresh();
}

/**
 * The form this fake statement needs: one transaction per line, two amount columns, a description.
 *
 * @return array<string, mixed>
 */
function mappingBody(): array
{
    return [
        'layout' => 'row',
        'date' => ['type' => 'index', 'value' => 0, 'format' => 'DD.MM.YYYY'],
        'amounts' => [
            'mode' => 'debit_credit',
            'debit' => ['type' => 'index', 'value' => 2],
            'credit' => ['type' => 'index', 'value' => 3],
            'decimal_separator' => ',',
            'thousands_separator' => '.',
        ],
        'description' => ['type' => 'index', 'value' => 1],
    ];
}

it('stores the statement as a document that is never sent to a model', function (): void {
    [$user, $subjectId, $accountId] = importer();

    $import = startImport($this, $user, $subjectId, $accountId);
    $document = Document::withoutGlobalScope(TaxSubjectScope::class)->findOrFail($import->document_id);

    // `IMPORT` is what stops `ProcessScannedDocument` asking a model to re-read a file whose rows
    // are about to be read locally.
    expect($document->source)->toBe(DocumentSource::IMPORT)
        ->and($import->status)->toBe(StatementImportStatus::MAPPING);
});

it('builds rows from the mapping, and imports the ticked ones', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId);

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", mappingBody())
        ->assertOk();

    $shown = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}")
        ->assertOk();

    expect($shown->json('total'))->toBe(3)
        // Money out is the debit column, made negative; money in is the credit column.
        ->and($shown->json('rows.0.amount_minor'))->toBe(-12_345)
        ->and($shown->json('rows.1.amount_minor'))->toBe(750_000)
        ->and($shown->json('rows.0.description'))->toBe('PLATA CARD KAUFLAND');

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/commit")
        ->assertStatus(202);

    $transactions = FinancialTransaction::withoutGlobalScope(TaxSubjectScope::class)->get();

    expect($transactions)->toHaveCount(3)
        ->and($transactions->every(static fn (FinancialTransaction $transaction): bool => $transaction->source === RecordSource::IMPORT))->toBeTrue()
        ->and(StatementImport::withoutGlobalScope(TaxSubjectScope::class)->findOrFail($import->id)->status)
        ->toBe(StatementImportStatus::IMPORTED);
});

it('adds nothing when the same statement is imported twice', function (): void {
    [$user, $subjectId, $accountId] = importer();

    $first = startImport($this, $user, $subjectId, $accountId);
    $this->actingAs($user)->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$first->id}/mapping", mappingBody())->assertOk();
    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$first->id}/commit")->assertStatus(202);

    expect(FinancialTransaction::withoutGlobalScope(TaxSubjectScope::class)->count())->toBe(3);

    // A second export covering the same days: a different file, the same movements.
    $second = startImport($this, $user, $subjectId, $accountId, 'extras-reexport.csv');
    $this->actingAs($user)->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$second->id}/mapping", mappingBody())->assertOk();

    $shown = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$second->id}")
        ->assertOk();

    // Every row is recognised, including the two identical coffees: the occurrence number in the
    // fingerprint keeps them apart from each other and matches them across imports.
    expect($shown->json('import.duplicate_count'))->toBe(3);

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$second->id}/commit")->assertStatus(202);

    expect(FinancialTransaction::withoutGlobalScope(TaxSubjectScope::class)->count())->toBe(3);
});

it('takes every transaction back out when an import is undone', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId);

    $this->actingAs($user)->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", mappingBody())->assertOk();
    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/commit")->assertStatus(202);

    expect(FinancialTransaction::withoutGlobalScope(TaxSubjectScope::class)->count())->toBe(3);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/undo")
        ->assertOk();

    expect(FinancialTransaction::withoutGlobalScope(TaxSubjectScope::class)->count())->toBe(0)
        ->and(StatementImport::withoutGlobalScope(TaxSubjectScope::class)->findOrFail($import->id)->status)
        ->toBe(StatementImportStatus::UNDONE);
});

/**
 * A statement written as blocks: one line opens a transaction, the lines under it are its details.
 *
 * The shape a Romanian bank actually exports — dates written in words, the merchant on a detail
 * line, and the two directions labelled differently.
 */
it('folds a transactions detail lines into it, per direction', function (): void {
    [$user, $subjectId, $accountId] = importer();

    /** @var FakeParseGateway $gateway */
    $gateway = app(StatementParseGateway::class);
    $gateway->rows = [
        ['12 septembrie 2026', 'Cumparare POS', '155,47', ''],
        ['', 'Tranzactie la: DIGI ROMANIA SA', '', ''],
        ['', 'Numar autorizare: 004521', '', ''],
        // An identifier the ledger has no use for: named on the form, and never read.
        ['', 'CNP: 1234567890123', '', ''],
        ['14 septembrie 2026', 'Incasare', '', '2.500,00'],
        ['', 'Ordonator: ACME SRL', '', ''],
        ['', 'Referinta: TRN99', '', ''],
    ];

    $import = startImport($this, $user, $subjectId, $accountId);

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", [
            'layout' => 'block',
            'date' => ['type' => 'index', 'value' => 0, 'format' => 'D MONTH YYYY'],
            'amounts' => [
                'mode' => 'debit_credit',
                'debit' => ['type' => 'index', 'value' => 2],
                'credit' => ['type' => 'index', 'value' => 3],
                'decimal_separator' => ',',
                'thousands_separator' => '.',
            ],
            'description' => ['type' => 'index', 'value' => 1],
            'counterparty' => [
                'debit' => ['type' => 'label', 'value' => 'Tranzactie la'],
                'credit' => ['type' => 'label', 'value' => 'Ordonator'],
            ],
            'reference' => [
                'debit' => ['type' => 'label', 'value' => 'Numar autorizare'],
                'credit' => ['type' => 'label', 'value' => 'Referinta'],
            ],
            'identity_labels' => ['CNP'],
        ])
        ->assertOk();

    $shown = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}")
        ->assertOk();

    // Seven lines, two transactions: the detail lines were folded in, not dropped and not counted.
    expect($shown->json('total'))->toBe(2)
        ->and($shown->json('import.lines_read'))->toBe(7)
        ->and($shown->json('import.lines_folded'))->toBe(5)
        ->and($shown->json('rows.0.booked_at'))->toBe('2026-09-12')
        ->and($shown->json('rows.0.amount_minor'))->toBe(-15_547)
        ->and($shown->json('rows.0.counterparty'))->toBe('DIGI ROMANIA SA')
        ->and($shown->json('rows.0.reference'))->toBe('004521')
        // The other direction is labelled differently, which is why the form asks twice.
        ->and($shown->json('rows.1.amount_minor'))->toBe(250_000)
        ->and($shown->json('rows.1.counterparty'))->toBe('ACME SRL')
        ->and($shown->json('rows.1.reference'))->toBe('TRN99');

    expect(json_encode($shown->json('rows'), JSON_THROW_ON_ERROR))->not->toContain('1234567890123');
});

it('refuses a mapping that reads almost none of the file', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId);

    // The description column as the date: nothing parses, and importing a fragment of a statement is
    // worse than importing none of it.
    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", array_merge(mappingBody(), [
            'date' => ['type' => 'index', 'value' => 1, 'format' => 'DD.MM.YYYY'],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'MAPPING_NOT_USABLE');
});

it('applies what a person answers to a whole set of rows at once', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId);

    $this->actingAs($user)->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", mappingBody())->assertOk();

    /** @var list<array<string, mixed>> $rows */
    $rows = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}")
        ->json('rows');

    /** @var list<string> $outgoing */
    $outgoing = collect($rows)
        ->filter(static fn (array $row): bool => is_numeric($row['amount_minor'] ?? null) && (int) $row['amount_minor'] < 0)
        ->pluck('id')
        ->values()
        ->all();

    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/rows", [
            'row_ids' => $outgoing,
            'category_code' => 'EXPENSE',
            'tags' => ['Alimente'],
        ])
        ->assertOk()
        ->assertJsonPath('changed', 2);

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/commit")->assertStatus(202);

    $listed = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions?classification=CLASSIFIED")
        ->assertOk();

    // Both outgoing rows arrive classified and tagged, with the tag normalised to one spelling.
    expect($listed->json('total'))->toBe(2)
        ->and($listed->json('transactions.0.tags.0.name'))->toBe('alimente');
});

/**
 * The classification job is handed someone to act as.
 *
 * It reads this person's tags and their remembered answers, both subject-scoped, and a queue worker
 * acts for nobody until it is told who — which is how a 1 700-row statement once ended up with every
 * row unproposed while the job died with `NoTenantContextException`. Asserted on the dispatch rather
 * than by running the job: in a test the queue is synchronous and the request's own tenant is still
 * bound, so the job would succeed here either way and prove nothing.
 */
it('hands the classification job someone to act as', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId);

    Queue::fake();

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", mappingBody())
        ->assertOk();

    Queue::assertPushed(ProposeRowCategories::class, function (ProposeRowCategories $job) use ($user): bool {
        return (new ReflectionProperty($job, 'actorId'))->getValue($job) === $user->id;
    });
});

it('deletes an import that never became transactions, and refuses one that did', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId);

    // Still waiting for its columns: nothing was written anywhere, so it can simply go.
    $this->actingAs($user)
        ->deleteJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}")
        ->assertNoContent();

    expect(StatementImport::withoutGlobalScope(TaxSubjectScope::class)->find($import->id))->toBeNull()
        ->and(StatementImportRow::withoutGlobalScope(TaxSubjectScope::class)->where('import_id', $import->id)->count())
        ->toBe(0);

    $second = startImport($this, $user, $subjectId, $accountId, 'extras-mai.csv');
    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$second->id}/mapping", mappingBody())
        ->assertOk();
    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$second->id}/commit")
        ->assertStatus(202);

    /*
     * One that wrote transactions is undone, never deleted: deleting it would leave figures in the
     * ledger pointing at an import nobody can look up, and no way to take them back out.
     */
    $this->actingAs($user)
        ->deleteJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$second->id}")
        ->assertStatus(409)
        ->assertJsonPath('reason', 'IMPORT_HOLDS_TRANSACTIONS');

    expect(StatementImport::withoutGlobalScope(TaxSubjectScope::class)->find($second->id))->not->toBeNull();
});

it('never lets one taxpayer see another import', function (): void {
    [$user, $subjectId, $accountId] = importer();
    [$otherUser, $otherSubjectId] = importer();

    $import = startImport($this, $user, $subjectId, $accountId);

    $this->actingAs($otherUser)
        ->getJson("/api/v1/subjects/{$otherSubjectId}/wallet/imports/{$import->id}")
        ->assertNotFound();
});
