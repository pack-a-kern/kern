<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Models\TaxSubject;
use App\Support\Crypto\BlindIndex\BlindIndexHasher;
use App\Support\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use App\Support\KeyManagement\Contracts\KeyManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Audit\Services\ChainVerifier;
use Tests\Fakes\InMemoryKeyManagementService;

/**
 * Proves the claim in docs/handoff/006-audit-chain.md §2 that
 * `Kern\Audit\Services\AuditChainService` is race-safe: multiple genuinely concurrent
 * processes — not just concurrent coroutines inside one PHP process/connection — appending to the
 * *same* chain must never produce a sequence gap, a duplicate sequence, or a hash-chain break.
 *
 * True concurrency requires separate OS processes, each with its own PDO connection to Postgres,
 * so `SELECT ... FOR UPDATE` on `audit_chain_heads` actually contends. `pcntl_fork()` gives us
 * that inside a single test; `DB::purge()` immediately after `fork()` (both in the child and,
 * defensively, in the parent right before forking) is mandatory — without it, forked children
 * would inherit and fight over the same underlying PDO socket file descriptor, which is undefined
 * behaviour, not a test of database-level locking.
 */
beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped(
            'Requires a real Postgres connection (DB_CONNECTION=pgsql) — see docs/handoff/006-audit-chain.md.',
        );
    }

    // Creating a TaxSubject/User provisions a per-subject DEK, so these tests need a key service.
    // Use the in-memory fake rather than live OpenBao: this suite is testing the audit chain, not
    // KMS integration, and a unit-level dependency on a running service makes it fragile.
    app()->instance(KeyManagementService::class, new InMemoryKeyManagementService());
    app()->instance(BlindIndexHasher::class, new BlindIndexHasher(
        new ConfigBlindIndexKeyProvider(
            json_encode(['1' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR),
            1,
        ),
    ));

    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires ext-pcntl for genuine multi-process concurrency.');
    }
});

test('concurrent appends to the same chain from separate processes produce no gaps or duplicates', function (): void {
    $chainKey = (string) TaxSubject::factory()->create()->getKey();
    $actorId = (string) User::factory()->create()->getKey();
    $workerCount = 5;
    $eventsPerWorker = 15;
    $totalEvents = $workerCount * $eventsPerWorker;

    // Never let a forked child inherit this process's live PDO socket.
    DB::purge();

    $pids = [];

    for ($worker = 0; $worker < $workerCount; $worker++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork() failed — cannot run the concurrency test.');
        }

        if ($pid === 0) {
            // Child process from here on. Force a brand new connection, independent of the
            // parent's and of every sibling's.
            DB::purge();

            $exitCode = 0;

            try {
                $service = app(AuditChainService::class);

                for ($i = 0; $i < $eventsPerWorker; $i++) {
                    // Jitter increases the chance concurrent transactions actually overlap
                    // instead of happening to run back-to-back.
                    usleep(random_int(0, 2000));

                    $service->record(AuditEventInput::occurringNow(
                        subjectId: $chainKey,
                        actorId: $actorId,
                        eventType: 'ConcurrentTestEvent',
                        resourceType: 'Test',
                        resourceId: (string) Str::uuid(),
                        payloadHash: hash('sha256', random_bytes(16)),
                    ));
                }
            } catch (Throwable $e) {
                fwrite(STDERR, "worker {$worker} failed: {$e->getMessage()}\n");
                $exitCode = 1;
            }

            exit($exitCode);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        expect(pcntl_wexitstatus($status))->toBe(0);
    }

    // Fresh connection to observe what the (independently committing) children actually wrote.
    DB::purge();

    /** @var \Illuminate\Support\Collection<int, int|string> $rawSequences */
    $rawSequences = DB::table('audit_events')
        ->where('chain_key', $chainKey)
        ->orderBy('sequence')
        ->pluck('sequence');

    $sequences = $rawSequences->map(static fn (int|string $sequence): int => (int) $sequence)->all();

    expect($sequences)->toBe(range(1, $totalEvents));

    /** @var object{last_sequence: int}|null $head */
    $head = DB::table('audit_chain_heads')->where('chain_key', $chainKey)->first();
    expect($head)->not->toBeNull();
    assert($head !== null);
    expect((int) $head->last_sequence)->toBe($totalEvents);

    $result = app(ChainVerifier::class)->verify($chainKey);
    expect($result->ok)->toBeTrue()
        ->and($result->verifiedCount)->toBe($totalEvents);
});
