<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\StatementImport\StatementImportTest;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Kern\Audit\Models\AuditEvent;
use Kern\Documents\Consent\DocumentAiConsentGate;
use Kern\Documents\Enums\DocumentSource;
use Kern\Documents\Enums\DocumentStatus;
use Kern\Documents\Models\Document;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\Banks\Models\Bank;
use Kern\TaxRomania\StatementImport\DataTransferObjects\ParsedStatementFile;
use Kern\TaxRomania\StatementImport\DataTransferObjects\StatementColumn;
use Kern\TaxRomania\StatementImport\Enums\StatementFormat;
use Kern\TaxRomania\StatementImport\Enums\StatementImportStatus;
use Kern\TaxRomania\StatementImport\Exceptions\StatementParseUnavailableException;
use Kern\TaxRomania\StatementImport\Exceptions\StatementRejectedException;
use Kern\TaxRomania\StatementImport\Gateways\StatementParseGateway;
use Kern\TaxRomania\StatementImport\Jobs\ProposeRowCategories;
use Kern\TaxRomania\StatementImport\Jobs\ReadStatementFile;
use Kern\TaxRomania\StatementImport\Jobs\WriteStatementRows;
use Kern\TaxRomania\StatementImport\Models\StatementImport;
use Kern\TaxRomania\StatementImport\Models\StatementImportRow;
use Kern\TaxRomania\StatementImport\Services\ClassifyCounterparties;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use ReflectionProperty;
use RuntimeException;
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

    // Asking a model about counterparties can be turned off for an installation, and the machine
    // running the suite may have it off. What the suite checks is the behaviour, not the operator's
    // choice, so it is pinned on here and turned off by the one test that is about it.
    config(['ai.statements.classification.enabled' => true]);

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

    /** @var list<list<string>> the lines above the header — where a statement prints its IBAN */
    public array $preamble = [];

    /** What `check` answers — a test of a PDF sets it to PDF. */
    public StatementFormat $format = StatementFormat::CSV;

    /** @var list<string> every file handed to `parse`, so a test can tell which bytes were read */
    public array $parsed = [];

    /** Set to refuse the file at the check, as a scan is refused. */
    public ?StatementRejectedException $refusal = null;

    public function check(string $contents): StatementFormat
    {
        if ($this->refusal !== null) {
            throw $this->refusal;
        }

        return $this->format;
    }

    public function parse(string $contents, ?string $sheet = null): ParsedStatementFile
    {
        $this->parsed[] = $contents;

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
            preamble: $this->preamble,
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
    $import = StatementImport::withoutGlobalScope(TenantScope::class)->findOrFail($importId);
    Document::withoutGlobalScope(TenantScope::class)
        ->whereKey($import->document_id)
        ->update(['status' => DocumentStatus::READY->value]);

    app()->call([app(ReadStatementFile::class, ['importId' => $importId]), 'handle']);

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

it('takes the reference from the next place when the first repeats the counterparty', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $gateway = fakeReader();

    // A card export of the kind this exists for: the reference column holds the payee's name again,
    // and the row's own identity sits one column further along.
    $gateway->rows = [
        ['05.03.2026', 'PLATA CARD', '123,45', '', 'KAUFLAND', 'AUT 004521'],
        ['06.03.2026', 'PLATA CARD', '123,45', '', 'KAUFLAND', 'AUT 004522'],
    ];

    $import = startImport($this, $user, $subjectId, $accountId, 'extras-referinte.csv');

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", [
            ...mappingBody(),
            'counterparty' => ['debit' => ['type' => 'index', 'value' => 4]],
            'reference' => [
                // Two places: the first is the counterparty's own column, which is what makes it
                // worthless as identity, so each row falls through to the second.
                'debit' => [
                    ['type' => 'index', 'value' => 4],
                    ['type' => 'index', 'value' => 5],
                ],
            ],
        ])
        ->assertOk();

    $rows = StatementImportRow::withoutGlobalScope(TenantScope::class)
        ->where('import_id', $import->id)
        ->orderBy('row_number')
        ->get();

    // Each row keeps its own identity, so the two are not one payment read twice.
    expect($rows->pluck('reference_ciphertext')->all())->toBe(['AUT 004521', 'AUT 004522'])
        ->and($rows->pluck('fingerprint')->unique())->toHaveCount(2);

    // And the choice survives the round trip, so going back to the columns still shows it.
    $shown = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}")
        ->assertOk();

    expect($shown->json('mapping.reference.debit.1.value'))->toBe(5)
        ->and($shown->json('multi_source.fields'))->toContain('reference');
});

it('stores the statement as a document that is never sent to a model', function (): void {
    [$user, $subjectId, $accountId] = importer();

    $import = startImport($this, $user, $subjectId, $accountId);
    $document = Document::withoutGlobalScope(TenantScope::class)->findOrFail($import->document_id);

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

    $transactions = FinancialTransaction::withoutGlobalScope(TenantScope::class)->get();

    expect($transactions)->toHaveCount(3)
        ->and($transactions->every(static fn (FinancialTransaction $transaction): bool => $transaction->source === RecordSource::IMPORT))->toBeTrue()
        ->and(StatementImport::withoutGlobalScope(TenantScope::class)->findOrFail($import->id)->status)
        ->toBe(StatementImportStatus::IMPORTED);
});

it('adds nothing when the same statement is imported twice', function (): void {
    [$user, $subjectId, $accountId] = importer();

    $first = startImport($this, $user, $subjectId, $accountId);
    $this->actingAs($user)->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$first->id}/mapping", mappingBody())->assertOk();
    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$first->id}/commit")->assertStatus(202);

    expect(FinancialTransaction::withoutGlobalScope(TenantScope::class)->count())->toBe(3);

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

    expect(FinancialTransaction::withoutGlobalScope(TenantScope::class)->count())->toBe(3);
});

it('takes the transactions, the rows and the file with it when an import is deleted', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId);

    $this->actingAs($user)->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", mappingBody())->assertOk();
    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/commit")->assertStatus(202);

    $documentId = StatementImport::withoutGlobalScope(TenantScope::class)->findOrFail($import->id)->document_id;

    expect(FinancialTransaction::withoutGlobalScope(TenantScope::class)->count())->toBe(3)
        ->and($documentId)->not->toBeNull();

    $this->actingAs($user)
        ->deleteJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}")
        ->assertNoContent();

    // One act: the ledger, the import and the statement file.
    expect(FinancialTransaction::withoutGlobalScope(TenantScope::class)->count())->toBe(0)
        ->and(StatementImport::withoutGlobalScope(TenantScope::class)->whereKey($import->id)->exists())->toBeFalse()
        ->and(Document::withoutGlobalScope(TenantScope::class)->whereKey($documentId)->exists())->toBeFalse();
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
/**
 * The preview answers from the same builder the import uses, over the rows already stored.
 *
 * That is the whole point of it being a request rather than a copy of the folding logic in the
 * browser: a second implementation would drift, and a preview that shows something other than what
 * will be saved is worse than no preview.
 */
it('shows what a form would make of the first lines, without staging anything', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId, 'extras-previzualizare.csv');

    $response = $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping/preview", mappingBody())
        ->assertOk();

    expect($response->json('transactions.0.booked_at'))->toBe('2026-03-05')
        ->and($response->json('transactions.0.amount_minor'))->toBe(-12_345)
        ->and($response->json('transactions.0.text'))->toBe('PLATA CARD KAUFLAND');

    // Nothing was staged and nothing moved: previewing is reading.
    expect(StatementImportRow::withoutGlobalScope(TenantScope::class)->where('import_id', $import->id)->count())
        ->toBe(0)
        ->and(StatementImport::withoutGlobalScope(TenantScope::class)->findOrFail($import->id)->status)
        ->toBe(StatementImportStatus::MAPPING);
});

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

/**
 * A statement in one currency states it nowhere, and a source has not carried one since it became a
 * source. When neither knows, nobody does — and a transaction's currency column is not nullable.
 *
 * Refused while the form is still open, rather than staged as rows that can never be imported.
 */
it('refuses a mapping when neither the file nor the account says the currency', function (): void {
    [$user, $subjectId] = importer();

    $account = FinancialAccount::create([
        'tax_subject_id' => $subjectId,
        'kind' => AccountKind::GENERAL,
        'display_name_ciphertext' => null,
        'currency_code' => null,
        'source' => RecordSource::MANUAL,
    ]);

    $import = startImport($this, $user, $subjectId, $account->id, 'extras-fara-moneda.csv');

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", mappingBody())
        ->assertStatus(422)
        ->assertJsonPath('reason', 'CURRENCY_REQUIRED');

    expect(StatementImportRow::withoutGlobalScope(TenantScope::class)->where('import_id', $import->id)->count())
        ->toBe(0);

    // Answering it on the form is enough — and it is the only place it can be answered.
    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", array_merge(mappingBody(), [
            'currency' => ['type' => 'code', 'value' => 'RON'],
        ]))
        ->assertOk();

    $shown = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}")
        ->assertOk();

    expect($shown->json('rows.0.currency_code'))->toBe('RON')
        ->and($shown->json('rows.0.included'))->toBeTrue();
});

/**
 * An import can reach the end having written nothing — every row unticked, say.
 *
 * What decides whether it can be deleted is the ledger, not the label: refusing on the strength of
 * `IMPORTED` alone would leave a person with an entry they can neither use nor remove.
 */
it('deletes an import that reached the end having written nothing', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId, 'extras-nebifat.csv');

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", mappingBody())
        ->assertOk();

    /** @var list<array<string, mixed>> $rows */
    $rows = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}")
        ->json('rows');

    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/rows", [
            'row_ids' => collect($rows)->pluck('id')->all(),
            'included' => false,
        ])
        ->assertOk();

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/commit")
        ->assertStatus(202);

    expect(FinancialTransaction::withoutGlobalScope(TenantScope::class)->count())->toBe(0);

    $this->actingAs($user)
        ->deleteJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}")
        ->assertNoContent();

    expect(StatementImport::withoutGlobalScope(TenantScope::class)->find($import->id))->toBeNull();
});

it('deletes an import whether or not it became transactions, and refuses only one being written', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId);

    // Still waiting for its columns: nothing was written anywhere, so it can simply go.
    $this->actingAs($user)
        ->deleteJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}")
        ->assertNoContent();

    expect(StatementImport::withoutGlobalScope(TenantScope::class)->find($import->id))->toBeNull()
        ->and(StatementImportRow::withoutGlobalScope(TenantScope::class)->where('import_id', $import->id)->count())
        ->toBe(0);

    $second = startImport($this, $user, $subjectId, $accountId, 'extras-mai.csv');
    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$second->id}/mapping", mappingBody())
        ->assertOk();
    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$second->id}/commit")
        ->assertStatus(202);

    // One that wrote transactions goes the same way: they come out of the ledger with it, which is
    // what "this import was a mistake" has always meant.
    $this->actingAs($user)
        ->deleteJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$second->id}")
        ->assertNoContent();

    expect(StatementImport::withoutGlobalScope(TenantScope::class)->find($second->id))->toBeNull()
        ->and(FinancialTransaction::withoutGlobalScope(TenantScope::class)->where('import_id', $second->id)->count())
        ->toBe(0);

    // Only a write in flight refuses: deleting underneath it would corrupt the write itself.
    $third = startImport($this, $user, $subjectId, $accountId, 'extras-iunie.csv');
    StatementImport::withoutGlobalScope(TenantScope::class)
        ->whereKey($third->id)
        ->update(['status' => StatementImportStatus::IMPORTING->value]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$third->id}")
        ->assertStatus(409)
        ->assertJsonPath('reason', 'IMPORT_HOLDS_TRANSACTIONS');

    expect(StatementImport::withoutGlobalScope(TenantScope::class)->find($third->id))->not->toBeNull();
});

it('serves the page of rows that was asked for', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId, 'extras-pagini.csv');

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", mappingBody())
        ->assertOk();

    $url = "/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}?per_page=2";

    /** @var list<array<string, mixed>> $first */
    $first = $this->actingAs($user)->getJson("{$url}&page=1")->assertOk()->json('rows');
    /** @var list<array<string, mixed>> $second */
    $second = $this->actingAs($user)->getJson("{$url}&page=2")->assertOk()->json('rows');

    // A query string is text: reading `page` as `is_int` once served page one for every page asked.
    expect($first)->toHaveCount(2)
        ->and($second)->toHaveCount(1)
        ->and($second[0]['row_number'] ?? null)->toBe(3);
});

/**
 * Batches are cut between counterparties, never through one — and their size is configuration.
 *
 * The fake statement has two counterparties: a supermarket paid twice, and a salary received once.
 */
it('keeps a counterparty in one batch, at whatever size is configured', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId, 'extras-loturi.csv');

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", mappingBody())
        ->assertOk();

    /** @var list<StatementImportRow> $rows */
    $rows = StatementImportRow::withoutGlobalScope(TenantScope::class)
        ->where('import_id', $import->id)
        ->orderBy('row_number')
        ->get()
        ->all();

    config(['ai.statements.classification.batch' => 1]);
    $small = app(ClassifyCounterparties::class)->batches($rows);

    config(['ai.statements.classification.batch' => 1000]);
    $large = app(ClassifyCounterparties::class)->batches($rows);

    expect($small)->toHaveCount(2)
        ->and($small[0])->toHaveCount(2)
        ->and($small[1])->toHaveCount(1)
        ->and($large)->toHaveCount(1)
        ->and($large[0])->toHaveCount(3);
});

/**
 * The moment the rows are staged, the import says suggestions are coming — before any job has run.
 *
 * Otherwise the screen would still hold the previous round, finished, and show a review about to be
 * rewritten under the person reading it.
 */
it('says suggestions are coming as soon as the rows are staged', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId, 'extras-in-asteptare.csv');

    Queue::fake();

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", mappingBody())
        ->assertOk();

    $shown = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}")
        ->assertOk();

    expect($shown->json('import.status'))->toBe('REVIEW')
        ->and($shown->json('preview.classification'))->not->toBeNull()
        ->and($shown->json('preview.classification.expected'))->toBeNull()
        ->and($shown->json('preview.classification.failure'))->toBeNull();
});

it('asks no model at all where an installation has turned the suggestions off', function (): void {
    config(['ai.statements.classification.enabled' => false]);

    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId, 'extras-fara-sugestii.csv');

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", mappingBody())
        ->assertOk();

    $shown = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}")
        ->assertOk();

    // The round is over before it starts: the rows are staged and the review opens, with nothing
    // waiting on a call that will never be made.
    expect($shown->json('import.status'))->toBe('REVIEW')
        ->and($shown->json('preview.classification.expected'))->toBe(0)
        ->and($shown->json('preview.classification.failure'))->toBe('TURNED_OFF')
        ->and($shown->json('proposals.ai'))->toBe(0);
});

it('records that no model was asked, so the screen does not wait for one', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId, 'extras-fara-ai.csv');

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", mappingBody())
        ->assertOk();

    $shown = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}")
        ->assertOk();

    expect($shown->json('preview.classification.expected'))->toBe(0)
        ->and($shown->json('preview.classification.failure'))->toBe('UNAVAILABLE')
        ->and($shown->json('proposals.ai'))->toBe(0);
});

/*
 * A job the queue gave up on used to leave its import where it was, for ever: READING or IMPORTING,
 * with nothing left that would move it on.
 */
it('marks an import failed when the reader stays unreachable', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId, 'extras-cititor-oprit.csv');

    StatementImport::withoutGlobalScope(TenantScope::class)->whereKey($import->id)
        ->update(['status' => StatementImportStatus::READING->value]);

    (new ReadStatementFile($import->id))->failed(StatementParseUnavailableException::requestFailed(new RuntimeException('down')));

    $failed = StatementImport::withoutGlobalScope(TenantScope::class)->findOrFail($import->id);

    expect($failed->status)->toBe(StatementImportStatus::FAILED)
        ->and($failed->failure_reason)->toBe('READER_UNAVAILABLE');
});

it('marks a write that stopped part-way failed, and lets it be undone', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId, 'extras-scriere-oprita.csv');

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", mappingBody())
        ->assertOk();
    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/commit")
        ->assertStatus(202);

    // As if the queue gave up while writing: the rows written so far are in the ledger.
    StatementImport::withoutGlobalScope(TenantScope::class)->whereKey($import->id)
        ->update(['status' => StatementImportStatus::IMPORTING->value]);
    $written = FinancialTransaction::withoutGlobalScope(TenantScope::class)->where('import_id', $import->id)->count();

    (new WriteStatementRows($import->id, $user->id))->failed(new RuntimeException('killed'));

    $failed = StatementImport::withoutGlobalScope(TenantScope::class)->findOrFail($import->id);

    expect($written)->toBeGreaterThan(0)
        ->and($failed->status)->toBe(StatementImportStatus::FAILED)
        ->and($failed->failure_reason)->toBe('WRITE_FAILED')
        ->and($failed->imported_count)->toBe($written);

    // A write that died part-way leaves transactions nothing else can reach: deleting the import is
    // what takes them back out.
    $this->actingAs($user)
        ->deleteJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}")
        ->assertNoContent();

    expect(FinancialTransaction::withoutGlobalScope(TenantScope::class)->where('import_id', $import->id)->count())->toBe(0)
        ->and(StatementImport::withoutGlobalScope(TenantScope::class)->whereKey($import->id)->exists())->toBeFalse();
});

it('asks the model again about unanswered rows without rebuilding them', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId, 'extras-din-nou.csv');

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", mappingBody())
        ->assertOk();

    $rowIds = StatementImportRow::withoutGlobalScope(TenantScope::class)->where('import_id', $import->id)->pluck('id')->all();

    Queue::fake();

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/suggestions")
        ->assertStatus(202);

    Queue::assertPushed(ProposeRowCategories::class, function (ProposeRowCategories $job) use ($user): bool {
        return (new ReflectionProperty($job, 'actorId'))->getValue($job) === $user->id;
    });

    $shown = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}")->assertOk();

    // The same rows, not a new set — a person's edits on them survive — and a round is pending.
    expect(StatementImportRow::withoutGlobalScope(TenantScope::class)->where('import_id', $import->id)->pluck('id')->all())
        ->toBe($rowIds)
        ->and($shown->json('preview.classification.expected'))->toBeNull();
});

it('reads the file again and asks for the columns again, without a new upload', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId, 'extras-coloane-din-nou.csv');

    Queue::fake();

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping/suggest")
        ->assertStatus(202);

    Queue::assertPushed(ReadStatementFile::class);

    expect(StatementImport::withoutGlobalScope(TenantScope::class)->findOrFail($import->id)->status)
        ->toBe(StatementImportStatus::READING);
});

it('refuses to ask again once an import is written', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId, 'extras-deja-scris.csv');

    $this->actingAs($user)->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", mappingBody())->assertOk();
    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/commit")->assertStatus(202);

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/suggestions")->assertStatus(409);
    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping/suggest")->assertStatus(409);
});

/*
 * A statement takes minutes to read and to classify, and nobody should have to keep the page open to
 * find out it is their turn. The person who started it is told — and only they are.
 */
it('tells the person who started an import when it moves on', function (): void {
    [$user, $subjectId, $accountId] = importer();
    [$someoneElse] = importer();
    $import = startImport($this, $user, $subjectId, $accountId, 'extras-anunt.csv');

    $this->actingAs($user)->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", mappingBody())->assertOk();
    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/commit")->assertStatus(202);

    $bell = $this->actingAs($user)->getJson('/api/v1/notifications')->assertOk();

    /** @var list<array<string, mixed>> $notices */
    $notices = $bell->json('notifications');
    $kinds = collect($notices)->pluck('data.kind')->all();

    // Newest first. No "ready for review": with no model to ask, the review opened at once.
    expect($kinds)->toBe(['IMPORTED', 'READY_FOR_MAPPING'])
        ->and($bell->json('unread'))->toBe(2)
        ->and($bell->json('notifications.0.data.import_id'))->toBe($import->id)
        ->and($bell->json('notifications.0.data.imported_count'))->toBe(3);

    // Ids, counts and codes only — nothing from the statement itself.
    expect(json_encode($notices, JSON_THROW_ON_ERROR))->not->toContain('KAUFLAND');

    $this->actingAs($someoneElse)->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'notifications');

    $this->actingAs($user)->postJson('/api/v1/notifications/read')->assertNoContent();
    $this->actingAs($user)->getJson('/api/v1/notifications')->assertJsonPath('unread', 0);
});

it('never lets one taxpayer see another import', function (): void {
    [$user, $subjectId, $accountId] = importer();
    [$otherUser, $otherSubjectId] = importer();

    $import = startImport($this, $user, $subjectId, $accountId);

    $this->actingAs($otherUser)
        ->getJson("/api/v1/subjects/{$otherSubjectId}/wallet/imports/{$import->id}")
        ->assertNotFound();
});

/*
 * A PDF statement converted to CSV by the provider before it is read.
 */

/** The faked reader, typed as what it is. */
function fakeReader(): FakeParseGateway
{
    /** @var FakeParseGateway $gateway */
    $gateway = app(StatementParseGateway::class);

    return $gateway;
}

/**
 * A provider that can convert, a taxpayer who has consented, and the converter's answer.
 *
 * @param  array<string, mixed>|null  $reply
 */
function convertingProvider(string $subjectId, int $status = 200, ?array $reply = null): void
{
    config([
        'ai.providers.env_fallback.enabled' => true,
        'ai.providers.env_fallback.provider' => 'ollama',
        'ai.providers.env_fallback.base_url' => 'https://gateway.test',
        'ai.providers.env_fallback.model' => 'any',
        'ai.providers.supports_conversion' => true,
    ]);
    app(DocumentAiConsentGate::class)->grant($subjectId);

    Http::fake(static function (HttpRequest $request) use ($status, $reply) {
        // Only the conversion is answered: the mapping call that follows is refused, which the
        // import survives by asking the person, and which keeps these tests about the conversion.
        return isset($request->data()['docling'])
            ? Http::response($reply ?? [
                'content' => "Data,Detalii,Debit,Credit\n05.03.2026,PLATA CARD,\"123,45\",\n",
                'format' => 'csv',
                'pipeline' => 'cpu-layout',
                'ms' => 16500,
                'warning' => 'one table spans two pages',
            ], $status)
            : Http::response([], 500);
    });
}

/** @return list<HttpRequest> */
function conversionRequests(): array
{
    $requests = Http::recorded(static fn (HttpRequest $request): bool => isset($request->data()['docling']))
        ->map(static fn (array $pair): HttpRequest => $pair[0])
        ->values()
        ->all();

    return array_values($requests);
}

it('reads a PDF as the CSV the provider converted it to, and keeps that CSV for the second reading', function (): void {
    [$user, $subjectId, $accountId] = importer();
    convertingProvider($subjectId);
    $gateway = fakeReader();
    $gateway->format = StatementFormat::PDF;

    $import = startImport($this, $user, $subjectId, $accountId, 'extras-convertit.csv');

    $sent = conversionRequests();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->data()['docling'])->toBe(['format' => 'csv'])
        // Never the person's own filename.
        ->and($sent[0]->data()['messages'][0]['content'][0]['file']['filename'])->toBe('statement.pdf')
        ->and($sent[0]->data()['messages'][0]['content'][0]['file']['file_data'])->toStartWith('data:application/pdf;base64,')
        ->and($import->status)->toBe(StatementImportStatus::MAPPING)
        ->and($import->format)->toBe(StatementFormat::PDF)
        ->and(end($gateway->parsed))->toContain('PLATA CARD')
        ->and($import->preview()['conversion'])->toBe(['pipeline' => 'cpu-layout', 'ms' => 16500, 'warning' => 'one table spans two pages'])
        ->and(AuditEvent::query()->where('event_type', 'WalletStatementSentForConversion')->where('resource_id', $import->id)->count())->toBe(1);

    $this->actingAs($user)->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", mappingBody())->assertOk();

    // Confirming the columns read the stored CSV again, and sent nothing.
    expect(conversionRequests())->toHaveCount(1)
        ->and(end($gateway->parsed))->toContain('PLATA CARD');
});

it('stops and says so when the converter fails, and can be tried again', function (): void {
    [$user, $subjectId, $accountId] = importer();
    convertingProvider($subjectId, 503);
    fakeReader()->format = StatementFormat::PDF;

    $import = startImport($this, $user, $subjectId, $accountId, 'extras-convertor-cazut.csv');

    expect($import->status)->toBe(StatementImportStatus::FAILED)
        ->and($import->failure_reason)->toBe('CONVERSION_FAILED')
        // Not read another way behind the person's back.
        ->and(fakeReader()->parsed)->toBe([]);

    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}")
        ->assertOk()
        ->assertJsonPath('import.can_read_again', true);

    Queue::fake();

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping/suggest")
        ->assertStatus(202);

    Queue::assertPushed(ReadStatementFile::class);
    expect($import->refresh()->status)->toBe(StatementImportStatus::READING)
        ->and($import->failure_reason)->toBeNull();
});

it('refuses a converter reply that holds no CSV', function (): void {
    [$user, $subjectId, $accountId] = importer();
    convertingProvider($subjectId, 200, ['content' => '', 'format' => 'csv']);
    fakeReader()->format = StatementFormat::PDF;

    $import = startImport($this, $user, $subjectId, $accountId, 'extras-convertit-gol.csv');

    expect($import->failure_reason)->toBe('CONVERSION_EMPTY');
});

it('never sends a scan to be converted', function (): void {
    [$user, $subjectId, $accountId] = importer();
    convertingProvider($subjectId);
    fakeReader()->refusal = StatementRejectedException::withReason('NO_TEXT_LAYER', 'A scan.');

    $import = startImport($this, $user, $subjectId, $accountId, 'extras-scanat.csv');

    expect($import->failure_reason)->toBe('NO_TEXT_LAYER')
        ->and(conversionRequests())->toBe([]);
});

it('reads a PDF here, as before, when the taxpayer has not consented', function (): void {
    [$user, $subjectId, $accountId] = importer();
    convertingProvider($subjectId);
    app(DocumentAiConsentGate::class)->revoke($subjectId);
    fakeReader()->format = StatementFormat::PDF;

    $import = startImport($this, $user, $subjectId, $accountId, 'extras-fara-acord.csv');

    expect($import->status)->toBe(StatementImportStatus::MAPPING)
        ->and($import->preview()['conversion'])->toBeNull()
        ->and(conversionRequests())->toBe([]);
});

it('does not read again an import refused for what the file is', function (): void {
    [$user, $subjectId, $accountId] = importer();
    fakeReader()->refusal = StatementRejectedException::withReason('NO_TEXT_LAYER', 'A scan.');
    convertingProvider($subjectId);

    $import = startImport($this, $user, $subjectId, $accountId, 'extras-scanat-din-nou.csv');

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping/suggest")
        ->assertStatus(409);
});

/*
 * Which bank issued the statement: from its IBAN, else from the model, else the person.
 */

/**
 * A provider the mapping call reaches, answering with `$reply`; the requests it got are recorded.
 *
 * @param  array<string, mixed>  $reply
 */
function mappingProvider(string $subjectId, array $reply): void
{
    config([
        'ai.providers.env_fallback.enabled' => true,
        'ai.providers.env_fallback.provider' => 'ollama',
        'ai.providers.env_fallback.base_url' => 'https://gateway.test',
        'ai.providers.env_fallback.model' => 'any',
    ]);
    app(DocumentAiConsentGate::class)->grant($subjectId);

    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode($reply)]]]])]);
}

it('finds the bank from the IBAN in the heading, without telling the model the heading', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $bank = Bank::factory()->create(['bic' => 'BTRLRO22XXX', 'name' => 'Banca Transilvania']);
    mappingProvider($subjectId, ['bank' => 'Some Other Bank']);
    fakeReader()->preamble = [['Extras de cont'], ['IBAN: RO49 BTRL 0000 1234 5678 9012']];

    $import = startImport($this, $user, $subjectId, $accountId, 'extras-iban.csv');

    expect($import->bank_id)->toBe($bank->id)
        ->and($import->preview()['bank_source'])->toBe('IBAN');

    // The heading holds the account and its holder, and the IBAN already answered.
    Http::assertSent(static fn (HttpRequest $request): bool => ! str_contains((string) json_encode($request->data()), 'RO49'));
});

it('asks the model for the bank, shown the heading, when there is no IBAN', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $bank = Bank::factory()->create(['name' => 'Banca Transilvania', 'legal_name' => 'BANCA TRANSILVANIA S.A.']);
    Bank::factory()->create(['name' => 'Banca Comerciala Romana']);
    mappingProvider($subjectId, ['bank' => 'BANCA TRANSILVANIA S.A.']);
    fakeReader()->preamble = [['BANCA TRANSILVANIA'], ['Extras de cont']];

    $import = startImport($this, $user, $subjectId, $accountId, 'extras-fara-iban.csv');

    expect($import->bank_id)->toBe($bank->id)
        ->and($import->preview()['bank_source'])->toBe('AI');

    Http::assertSent(static fn (HttpRequest $request): bool => str_contains((string) json_encode($request->data()), 'Extras de cont'));
});

it('leaves the bank empty when the answer fits more than one', function (): void {
    [$user, $subjectId, $accountId] = importer();
    Bank::factory()->create(['name' => 'Banca Cooperativa Unu']);
    Bank::factory()->create(['name' => 'Banca Cooperativa Doi']);
    mappingProvider($subjectId, ['bank' => 'Banca Cooperativa']);

    $import = startImport($this, $user, $subjectId, $accountId, 'extras-banca-ambigua.csv');

    expect($import->bank_id)->toBeNull();
});

it('keeps the bank a person picks on the mapping screen', function (): void {
    [$user, $subjectId, $accountId] = importer();
    Bank::factory()->create(['bic' => 'BTRLRO22XXX']);
    $chosen = Bank::factory()->create();
    fakeReader()->preamble = [['IBAN: RO49BTRL0000123456789012']];

    $import = startImport($this, $user, $subjectId, $accountId, 'extras-banca-aleasa.csv');

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", [...mappingBody(), 'bank_id' => $chosen->id])
        ->assertOk();

    $import->refresh();

    expect($import->bank_id)->toBe($chosen->id)
        ->and($import->preview()['bank_source'])->toBeNull();
});

it('refuses a bank that is not in the directory', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $import = startImport($this, $user, $subjectId, $accountId, 'extras-banca-necunoscuta.csv');

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", [...mappingBody(), 'bank_id' => (string) \Illuminate\Support\Str::uuid()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('bank_id');
});

it('finds a bank that has since closed, for a statement from before it did', function (): void {
    [$user, $subjectId, $accountId] = importer();
    $alpha = Bank::factory()->create(['name' => 'Alpha Bank Romania', 'legal_name' => 'ALPHA BANK ROMANIA S.A.', 'bic' => null, 'active' => false]);
    Bank::factory()->create(['name' => 'UniCredit Bank']);
    mappingProvider($subjectId, ['bank' => 'Alpha Bank Romania']);
    fakeReader()->preamble = [['ALPHA BANK ROMANIA'], ['Extras de cont']];

    $import = startImport($this, $user, $subjectId, $accountId, 'extras-alpha.csv');

    expect($import->bank_id)->toBe($alpha->id);

    // And it can be chosen by hand, closed or not.
    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/imports/{$import->id}/mapping", [...mappingBody(), 'bank_id' => $alpha->id])
        ->assertOk();
});

it('lists closed banks only when asked, after the current ones', function (): void {
    [$user, $subjectId] = importer();
    Bank::factory()->create(['name' => 'Alpha Bank Romania', 'active' => false]);
    Bank::factory()->create(['name' => 'Banca Transilvania']);

    $current = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/banks")->assertOk();
    $all = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/banks?include_closed=1")->assertOk();

    expect($current->json('banks.*.name'))->toBe(['Banca Transilvania'])
        ->and($all->json('banks.*.name'))->toBe(['Banca Transilvania', 'Alpha Bank Romania']);
});
