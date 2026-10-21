<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\StatementImport\CounterpartyClassificationPayloadTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kern\Documents\Consent\DocumentAiConsentGate;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Tenancy\TenantContext;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\StatementImport\Enums\StatementImportStatus;
use Kern\TaxRomania\StatementImport\Models\StatementImport;
use Kern\TaxRomania\StatementImport\Models\StatementImportRow;
use Kern\TaxRomania\StatementImport\Services\ClassifyCounterparties;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;

/**
 * What actually leaves this application when a statement's counterparties are put to a model.
 *
 * Asserted against the request the HTTP client sends rather than against the arguments handed to
 * the client, because the request is the thing that leaves: a payload assembled correctly and then
 * dropped by the dialect would pass the other test and send nothing.
 */
beforeEach(function (): void {
    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );

    // A provider that exists, consents given, and nothing actually dialled.
    config([
        'ai.providers.defaults.enabled' => true,
        'ai.providers.defaults.provider' => 'openai',
        'ai.providers.defaults.base_url' => 'https://model.test',
        'ai.providers.defaults.model' => 'test-model',
        'ai.providers.defaults.api_key' => 'k',
    ]);

});

/**
 * The payload as it left, decoded back out of the request body.
 *
 * @return array<string, mixed>
 */
function sentPayload(): array
{
    /** @var array<string, mixed> $sent */
    $sent = [];

    Http::assertSent(function (Request $request) use (&$sent): bool {
        $body = $request->data();
        $text = json_encode($body);

        // The dialect decides where the question sits; find the JSON object we put in it.
        if (preg_match('/\{\\\\"categories_in\\\\".*?\\\\"rows\\\\":\s*\d+\}\]\}/', (string) $text, $m) === 1) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode(str_replace('\"', '"', $m[0]), true, 512, JSON_THROW_ON_ERROR);
            $sent = $decoded;
        }

        return true;
    });

    return $sent;
}

/**
 * A taxpayer with somebody acting for them, bound.
 *
 * The classifier reads this person's tags and their remembered answers, both subject-scoped, so it
 * needs a tenant the way the queue job that calls it does.
 *
 * @return array{0: string, 1: TaxSubject}
 */
function boundSubject(?string $displayName): array
{
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    // Assigned after creation rather than through the factory, which names every subject it makes
    // and would otherwise win.
    $subject->display_name_ciphertext = $displayName;
    $subject->save();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    app(TenantContext::class)->authorizeAndActivate($user->id, $subject->id);

    // Sending a person's counterparties to a model is consented, per subject, and the client checks
    // before anything leaves. Granted rather than faked away: this suite is about what a consented
    // request carries.
    app(DocumentAiConsentGate::class)->grant($subject->id);

    return [$subject->id, $subject];
}

/**
 * @param  list<array{name: string, description: string, amount: int}>  $lines
 * @return list<StatementImportRow>
 */
function classifiedRows(string $subjectId, array $lines): array
{
    $account = FinancialAccount::create([
        'tax_subject_id' => $subjectId,
        'kind' => AccountKind::GENERAL,
        'display_name_ciphertext' => null,
        'currency_code' => 'RON',
        'source' => RecordSource::MANUAL,
    ]);

    $import = StatementImport::create([
        'tax_subject_id' => $subjectId,
        'account_id' => $account->id,
        'status' => StatementImportStatus::REVIEW,
    ]);

    /** @var list<StatementImportRow> $rows */
    $rows = [];

    foreach ($lines as $index => $line) {
        $rows[] = StatementImportRow::create([
            'import_id' => $import->id,
            'tax_subject_id' => $subjectId,
            'row_number' => $index + 1,
            'booked_at' => '2026-03-05',
            'amount_minor' => $line['amount'],
            'currency_code' => 'RON',
            'description_ciphertext' => $line['description'],
            'counterparty_ciphertext' => $line['name'],
            'included' => true,
        ]);
    }

    return $rows;
}

it('sends every distinct description for a counterparty, not one sample', function (): void {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"results":[]}']]]])]);

    [$subjectId] = boundSubject('ion popescu');

    // One counterparty, four different things happening under it — the case a single sample cannot
    // represent, because it has to pick one of the four to speak for all of them.
    $rows = classifiedRows($subjectId, [
        ['name' => 'ION POPESCU', 'description' => 'Fixed Saver - Matured', 'amount' => 10_000],
        ['name' => 'ION POPESCU', 'description' => 'transfer bancar', 'amount' => 20_000],
        ['name' => 'ION POPESCU', 'description' => 'schimb personal', 'amount' => 30_000],
        ['name' => 'ION POPESCU', 'description' => 'Fixed Saver - Matured', 'amount' => 40_000],
    ]);

    app(ClassifyCounterparties::class)->classify($subjectId, $rows);

    $payload = sentPayload();
    /** @var array<string, mixed> $counterparty */
    $counterparty = data_get($payload, 'counterparties.0', []);

    expect($counterparty)->toHaveKey('descriptions')
        ->and($counterparty)->not->toHaveKey('description')
        // Three, not four: the repeat is folded, which is what "distinct" means.
        ->and($counterparty['descriptions'])->toBe(['Fixed Saver - Matured', 'transfer bancar', 'schimb personal'])
        ->and($counterparty['rows'])->toBe(4);
});

it('caps how many descriptions one counterparty may send', function (): void {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"results":[]}']]]])]);
    config(['ai.statements.classification.max_descriptions' => 2]);

    [$subjectId] = boundSubject('ion popescu');

    // A supermarket printing its branch: many lines, all the same merchant, nothing gained past the
    // first few.
    $rows = classifiedRows($subjectId, [
        ['name' => 'Mega Image', 'description' => 'MEGAIMAGE 0129 DOMNEST', 'amount' => -1_000],
        ['name' => 'Mega Image', 'description' => 'MEGAIMAGE 0388 G.OBOR1', 'amount' => -2_000],
        ['name' => 'Mega Image', 'description' => 'MEGAIMAGE 0124 VOILA O', 'amount' => -3_000],
    ]);

    app(ClassifyCounterparties::class)->classify($subjectId, $rows);

    expect(data_get(sentPayload(), 'counterparties.0.descriptions'))->toHaveCount(2);
});

it('puts the taxpayer into the prompt, because two of its rules name them', function (): void {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"results":[]}']]]])]);

    [$subjectId] = boundSubject('ion popescu');
    $rows = classifiedRows($subjectId, [
        ['name' => 'Mega Image', 'description' => 'MEGAIMAGE 0129', 'amount' => -1_000],
    ]);

    app(ClassifyCounterparties::class)->classify($subjectId, $rows);

    Http::assertSent(function (Request $request): bool {
        $body = (string) json_encode($request->data());

        // The rule is unusable while it names nobody, and a literal placeholder reaching a model is
        // worse than the rule being absent: it invites the model to reason about the braces.
        expect($body)->toContain('ion popescu')
            ->and($body)->not->toContain('{taxpayer}');

        return true;
    });
});

it('leaves the rule out rather than naming nobody, for a subject with no name', function (): void {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"results":[]}']]]])]);

    [$subjectId] = boundSubject(null);
    $rows = classifiedRows($subjectId, [
        ['name' => 'Mega Image', 'description' => 'MEGAIMAGE 0129', 'amount' => -1_000],
    ]);

    app(ClassifyCounterparties::class)->classify($subjectId, $rows);

    Http::assertSent(function (Request $request): bool {
        $body = (string) json_encode($request->data());

        expect($body)->not->toContain('{taxpayer}')
            // The lines that named them are gone; everything else stands.
            ->and($body)->not->toContain('is money between their own accounts')
            ->and($body)->toContain('Money leaving the account is spending');

        return true;
    });
});

it('lets the schema refuse a second tag, rather than only asking for one', function (): void {
    /*
     * The prompt tells the model its output is constrained. That claim has to be true of the thing
     * the application depends on, and `apply()` capping tags after the fact is not the same as the
     * model never returning two.
     */
    $schema = ClassifyCounterparties::schema();

    /** @var array<string, mixed> $tags */
    $tags = data_get($schema, 'properties.results.items.properties.tags');

    expect($tags['maxItems'])->toBe(1);
});
