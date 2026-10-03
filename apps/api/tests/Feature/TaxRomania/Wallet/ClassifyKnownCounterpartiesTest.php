<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\ClassifyKnownCounterpartiesTest;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Kern\Documents\Consent\DocumentAiConsentGate;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Tenancy\TenantContext;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\StatementImport\Jobs\ClassifyCounterpartyBatch;
use Kern\TaxRomania\StatementImport\Services\ClassifyKnownCounterparties;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\ProposalSource;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Models\Counterparty;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Tests\TestCase;

/**
 * The background classifier: what it asks about, and what it does with the answer.
 *
 * This is the work that used to happen during an import, where a person waited for it. A name is not
 * a property of the file it first appeared in — it is paid again every month — so it belongs on a
 * schedule, and the import belongs finished.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );

    config([
        'ai.providers.env_fallback.enabled' => true,
        'ai.providers.env_fallback.provider' => 'openai',
        'ai.providers.env_fallback.base_url' => 'https://model.test',
        'ai.providers.env_fallback.model' => 'test-model',
        'ai.providers.env_fallback.api_key' => 'k',
    ]);
});

/**
 * A model that answers with exactly this.
 *
 * @param  list<array{id: string, category: string|null, tags: list<string>}>  $results
 */
function modelSays(array $results): void
{
    Http::fake(['*' => Http::response(
        [
        'choices' => [['message' => ['content' => json_encode(['results' => $results], JSON_THROW_ON_ERROR)]]]],
    )]);
}

/** @return array{0: User, 1: string, 2: string} */
function classifiableSubject(): array
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

    app(DocumentAiConsentGate::class)->grant($subject->id);

    return [$user, $subject->id, $account->id];
}

/**
 * Every batch this taxpayer has, classified.
 *
 * What the queue does one job at a time. The classifier deliberately answers one batch per call, so
 * a worker timeout can only ever lose one — these tests are about the answers, not the queueing.
 *
 * @return array{asked: int, answered: int, transactions: int, failure: string|null}
 */
function classifyAll(string $subjectId): array
{
    $classifier = app(ClassifyKnownCounterparties::class);
    $total = ['asked' => 0, 'answered' => 0, 'transactions' => 0, 'failure' => null];

    foreach ($classifier->batches($subjectId) as $batch) {
        $outcome = $classifier->classifyBatch($subjectId, $batch);

        $total['asked'] += $outcome['asked'];
        $total['answered'] += $outcome['answered'];
        $total['transactions'] += $outcome['transactions'];
        $total['failure'] ??= $outcome['failure'];
    }

    return $total;
}

function spend(TestCase $case, User $user, string $subjectId, string $accountId, int $amount, string $counterparty): void
{
    $case->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => $accountId,
        'amount_minor' => $amount,
        'currency_code' => 'RON',
        'booked_at' => '2026-03-05',
        'description' => 'Cumparare POS',
        'counterparty' => $counterparty,
    ])->assertCreated();
}

it('answers a counterparty and classifies every unanswered transaction under it', function (): void {
    [$user, $subjectId, $accountId] = classifiableSubject();

    spend($this, $user, $subjectId, $accountId, -12_000, 'Mega Image');
    spend($this, $user, $subjectId, $accountId, -8_000, 'Mega Image');
    spend($this, $user, $subjectId, $accountId, -5_000, 'Mega Image');

    modelSays([['id' => 'c1', 'category' => 'EXPENSE', 'tags' => ['food']]]);

    $outcome = classifyAll($subjectId);

    // One name asked about, three rows answered — which is the whole reason the question is asked
    // per counterparty and not per transaction.
    expect($outcome['asked'])->toBe(1)
        ->and($outcome['answered'])->toBe(1)
        ->and($outcome['transactions'])->toBe(3);

    $counterparty = Counterparty::query()->sole();

    expect($counterparty->category_code)->toBe('EXPENSE')
        ->and($counterparty->source)->toBe(ProposalSource::AI)
        ->and($counterparty->analysed_at)->not->toBeNull();

    $response = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions")->assertOk();

    /** @var list<array<string, mixed>> $listedRows */
    $listedRows = $response->json('transactions');
    $categories = array_values(array_unique(array_map(
        static function (array $row): string {
            $code = data_get($row, 'splits.0.category_code');

            return is_string($code) ? $code : '';
        },
        $listedRows,
    )));

    expect($listedRows)->toHaveCount(3)
        ->and($categories)->toBe(['EXPENSE']);
});

it('never overwrites a category a person already chose', function (): void {
    [$user, $subjectId, $accountId] = classifiableSubject();

    spend($this, $user, $subjectId, $accountId, -12_000, 'Mega Image');
    spend($this, $user, $subjectId, $accountId, -8_000, 'Mega Image');

    $listed = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions")->assertOk();
    /** @var string $first */
    $first = $listed->json('transactions.0.id');
    /** @var int $amount */
    $amount = $listed->json('transactions.0.amount_minor');

    // Somebody says this one was a transfer. A model answering "EXPENSE" for the shop must not
    // quietly disagree with them.
    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$first}/splits", [
            // The splits have to add up to the transaction, so the amount comes from the row rather
            // than from the order the two were written in.
            'splits' => [['amount_minor' => $amount, 'category_code' => 'TRANSFER']],
        ])->assertNoContent();

    modelSays([['id' => 'c1', 'category' => 'EXPENSE', 'tags' => []]]);
    classifyAll($subjectId);

    $kept = FinancialTransaction::query()->findOrFail($first);

    expect($kept->splits()->sole()->category_code)->toBe('TRANSFER');
});

it('refuses a category that cannot go with the direction of the money', function (): void {
    [$user, $subjectId, $accountId] = classifiableSubject();

    spend($this, $user, $subjectId, $accountId, -12_000, 'Cineva');

    // Income, on money that left. The application drops these rather than filing a payment as
    // something nobody received.
    modelSays([['id' => 'c1', 'category' => 'DIVIDENDS_INTEREST', 'tags' => []]]);

    $outcome = classifyAll($subjectId);

    expect($outcome['answered'])->toBe(0)
        ->and(Counterparty::query()->sole()->category_code)->toBeNull();
});

it('does not ask twice about a name it has already looked at', function (): void {
    [$user, $subjectId, $accountId] = classifiableSubject();

    spend($this, $user, $subjectId, $accountId, -12_000, 'Ceva Necunoscut');

    // A model with nothing to say. Without a record of having looked, this name would come back
    // round every ten minutes for ever.
    modelSays([['id' => 'c1', 'category' => null, 'tags' => []]]);
    classifyAll($subjectId);

    expect(Counterparty::query()->sole()->analysed_at)->not->toBeNull();

    $second = classifyAll($subjectId);

    expect($second['asked'])->toBe(0);
});

it('says why it could not ask, rather than looking like it had nothing to do', function (): void {
    [$user, $subjectId, $accountId] = classifiableSubject();

    spend($this, $user, $subjectId, $accountId, -12_000, 'Mega Image');

    // Consent withdrawn: the client refuses before anything leaves.
    app(DocumentAiConsentGate::class)->revoke($subjectId);

    $outcome = classifyAll($subjectId);

    expect($outcome['failure'])->not->toBeNull()
        ->and($outcome['asked'])->toBe(0)
        // And the name is left unasked rather than marked as looked at, so it is picked up once
        // consent returns.
        ->and(Counterparty::query()->sole()->analysed_at)->toBeNull();
});

it('queues the work rather than doing it, because a model call is slow', function (): void {
    /*
     * The mistake this replaced. The command runs inside `RunOperationTask` on the `default` queue,
     * whose timeout is sixty seconds and whose configuration says model calls do not belong there —
     * and one batch is a hundred names in a single call of several minutes. Every scheduled run was
     * killed before the call returned, so nothing was ever written.
     *
     * So the command dispatches and the job asks, on the queue built for slow calls.
     */
    [$user, $subjectId, $accountId] = classifiableSubject();

    spend($this, $user, $subjectId, $accountId, -12_000, 'Mega Image');
    Queue::fake();

    $run = $this->artisan('kern:counterparties:classify');

    /** @var PendingCommand $run */
    /*
     * `run()` rather than leaving it to the destructor, which is where `PendingCommand` otherwise
     * executes — after the assertions below, which then see a queue nothing has been pushed to yet.
     */
    $run->expectsOutputToContain('Queued 1 batch(es) for 1 taxpayer(s).')->assertSuccessful()->run();

    Queue::assertPushedOn(config()->string('ai.providers.queue'), ClassifyCounterpartyBatch::class);
});

it('runs from the scheduler, where nothing has bound a tenant', function (): void {
    /*
     * The case the first version of these tests missed. They reached the classifier through
     * `actingAs` and an HTTP call, which leaves a tenant bound — so they passed while the scheduled
     * run failed every ten minutes with `NoTenantContextException`.
     *
     * Reading the worklist needs only the subject named; writing the answer needs a bound tenant. So
     * the command carries an owner to the job, and the job binds.
     */
    [$user, $subjectId, $accountId] = classifiableSubject();

    spend($this, $user, $subjectId, $accountId, -12_000, 'Mega Image');

    app()->forgetInstance(TenantContext::class);

    expect(app(TenantContext::class)->hasActiveTenant())->toBeFalse();

    modelSays([['id' => 'c1', 'category' => 'EXPENSE', 'tags' => ['food']]]);

    Queue::fake();
    $run = $this->artisan('kern:counterparties:classify');
    /** @var PendingCommand $run */
    $run->assertSuccessful()->run();

    // And the job it queued, run where the worker would run it: nobody bound.
    $queued = null;

    Queue::assertPushed(ClassifyCounterpartyBatch::class, function (ClassifyCounterpartyBatch $job) use (&$queued): bool {
        $queued = $job;

        return true;
    });

    expect($queued)->toBeInstanceOf(ClassifyCounterpartyBatch::class);

    /** @var ClassifyCounterpartyBatch $queued */
    app()->forgetInstance(TenantContext::class);
    app()->call([$queued, 'handle']);

    expect(Counterparty::query()->sole()->category_code)->toBe('EXPENSE');
});

it('skips a taxpayer nobody may act for, rather than inventing somebody', function (): void {
    [$user, $subjectId, $accountId] = classifiableSubject();

    spend($this, $user, $subjectId, $accountId, -12_000, 'Mega Image');

    // Every membership revoked: there is no principal to act as, and the gate exists to stop one
    // being invented.
    SubjectMembership::query()->where('subject_id', $subjectId)->update(['revoked_at' => now('UTC')]);
    app()->forgetInstance(TenantContext::class);
    Queue::fake();

    $run = $this->artisan('kern:counterparties:classify');

    /** @var PendingCommand $run */
    $run->expectsOutputToContain('1 taxpayer(s) skipped: nobody holds an unrevoked membership.')
        ->assertSuccessful()
        ->run();

    Queue::assertNothingPushed();
});

it('sizes the job for one call, on the queue that expects slow ones', function (): void {
    // The two numbers that stop this being killed again: the queue it sits on, and a timeout that
    // covers the call the client was told it could take.
    $job = new ClassifyCounterpartyBatch('subject', 'actor', ['id']);

    expect($job->queue)->toBe(config()->string('ai.providers.queue'))
        ->and($job->timeout)->toBe(
            config()->integer('ai.statements.timeout_seconds')
                + config()->integer('ai.providers.job_timeout_margin_seconds'),
        );
});
