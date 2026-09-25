<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Models\TaxSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kern\Audit\ChainVerificationFailureReason;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Audit\Services\ChainVerifier;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Tests\Fakes\InMemoryKeyManagementService;

/**
 * These tests exercise real Postgres features (row locking, a PL/pgSQL trigger) that sqlite
 * cannot provide — see docs/handoff/006-audit-chain.md "How to verify". They no-op (skip) unless
 * the test run is actually pointed at a pgsql connection.
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
});

/**
 * `audit_events.subject_id` and `actor_id` carry real foreign keys to `tax_subjects` / `users`
 * (see 2026_09_08_200005_add_identity_foreign_keys_to_audit_tables). A synthetic UUID therefore
 * no longer satisfies the schema, so these helpers create the real rows the chain refers to.
 */
function auditSubjectId(): string
{
    return (string) TaxSubject::factory()->create()->getKey();
}

function auditActorId(): string
{
    return (string) User::factory()->create()->getKey();
}

function auditInput(string $chainSubjectId, string $eventType = 'DocumentUploaded'): AuditEventInput
{
    return AuditEventInput::occurringNow(
        subjectId: $chainSubjectId,
        actorId: auditActorId(),
        eventType: $eventType,
        resourceType: 'Document',
        resourceId: (string) Str::uuid(),
        payloadHash: hash('sha256', $eventType . random_int(0, PHP_INT_MAX)),
    );
}

test('appending several events to the same subject builds a linked, sequential chain', function (): void {
    $service = app(AuditChainService::class);
    $subjectId = auditSubjectId();

    $first = $service->record(auditInput($subjectId, 'DocumentUploaded'));
    $second = $service->record(auditInput($subjectId, 'DocumentScanned'));
    $third = $service->record(auditInput($subjectId, 'DocumentExtractionAccepted'));

    expect($first->sequence)->toBe(1)
        ->and($second->sequence)->toBe(2)
        ->and($third->sequence)->toBe(3)
        ->and($first->previousHash)->toBe(AuditChainService::GENESIS_HASH)
        ->and($second->previousHash)->toBe($first->eventHash)
        ->and($third->previousHash)->toBe($second->eventHash)
        ->and($first->eventHash)->not->toBe($second->eventHash)
        ->and($second->eventHash)->not->toBe($third->eventHash);

    /** @var object{last_sequence: int, last_event_hash: string}|null $head */
    $head = DB::table('audit_chain_heads')->where('chain_key', $subjectId)->first();
    expect($head)->not->toBeNull();
    assert($head !== null);
    expect((int) $head->last_sequence)->toBe(3)
        ->and($head->last_event_hash)->toBe($third->eventHash);

    $result = app(ChainVerifier::class)->verify($subjectId);
    expect($result->ok)->toBeTrue()
        ->and($result->verifiedCount)->toBe(3);
});

test('two different subjects get two independent chains that both start at sequence 1', function (): void {
    $service = app(AuditChainService::class);
    $subjectA = auditSubjectId();
    $subjectB = auditSubjectId();

    $service->record(auditInput($subjectA));
    $eventA2 = $service->record(auditInput($subjectA));
    $eventB1 = $service->record(auditInput($subjectB));

    expect($eventA2->sequence)->toBe(2)
        ->and($eventB1->sequence)->toBe(1);
});

test('an event with no subject id is appended to the shared system chain', function (): void {
    $service = app(AuditChainService::class);

    $input = AuditEventInput::occurringNow(
        subjectId: null,
        actorId: auditActorId(),
        eventType: 'SecurityEvent',
        resourceType: 'Session',
        resourceId: null,
        payloadHash: hash('sha256', 'system-' . random_int(0, PHP_INT_MAX)),
    );

    $record = $service->record($input);

    expect($record->chainKey)->toBe(AuditChainService::SYSTEM_CHAIN_KEY)
        ->and($record->subjectId)->toBeNull();
});

test('the database itself rejects an UPDATE against audit_events', function (): void {
    $service = app(AuditChainService::class);
    $subjectId = auditSubjectId();
    $event = $service->record(auditInput($subjectId));

    expect(fn () => DB::table('audit_events')->where('id', $event->id)->update(['event_type' => 'Tampered']))
        ->toThrow(\Illuminate\Database\QueryException::class, 'append-only');
});

test('the database itself rejects a DELETE against audit_events', function (): void {
    $service = app(AuditChainService::class);
    $subjectId = auditSubjectId();
    $event = $service->record(auditInput($subjectId));

    expect(fn () => DB::table('audit_events')->where('id', $event->id)->delete())
        ->toThrow(\Illuminate\Database\QueryException::class, 'append-only');
});

test('the Eloquent model refuses mass-assignment entirely, before mutation is even attempted', function (): void {
    $service = app(AuditChainService::class);
    $subjectId = auditSubjectId();
    $event = $service->record(auditInput($subjectId));

    $model = \Kern\Audit\Models\AuditEvent::query()->findOrFail($event->id);

    // $guarded = ['*'] rejects this before performUpdate() (the override below) is ever reached
    // — an even earlier line of defence for the common ->update([...]) call shape.
    expect(fn () => $model->update(['event_type' => 'Tampered']))
        ->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class);
});

test('the Eloquent model refuses a direct property save() as a second line of defence', function (): void {
    $service = app(AuditChainService::class);
    $subjectId = auditSubjectId();
    $event = $service->record(auditInput($subjectId));

    $model = \Kern\Audit\Models\AuditEvent::query()->findOrFail($event->id);
    $model->event_type = 'Tampered';

    // Direct property assignment bypasses the mass-assignment guard entirely, so this exercises
    // AuditEvent::performUpdate() itself.
    expect(fn () => $model->save())->toThrow(LogicException::class, 'append-only');
});

test('the Eloquent model refuses delete()', function (): void {
    $service = app(AuditChainService::class);
    $subjectId = auditSubjectId();
    $event = $service->record(auditInput($subjectId));

    $model = \Kern\Audit\Models\AuditEvent::query()->findOrFail($event->id);

    expect(fn () => $model->delete())->toThrow(LogicException::class, 'append-only');
});

test(
    'tampering with a stored event bypassing the trigger is caught by verification at the exact sequence',
    function (): void {
        // Simulates BLUEPRINT.md §4.1's "administrator DB curios" threat: someone with enough Postgres
        // privilege to disable the append-only trigger, which the trigger itself cannot prevent —
        // that is exactly the scenario the hash chain (as opposed to the trigger alone) defends
        // against. See docs/handoff/006-audit-chain.md.
        $service = app(AuditChainService::class);
        $subjectId = auditSubjectId();

        $first = $service->record(auditInput($subjectId, 'DocumentUploaded'));
        $second = $service->record(auditInput($subjectId, 'DocumentScanned'));
        $third = $service->record(auditInput($subjectId, 'DocumentExtractionAccepted'));

        DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update');

        try {
            DB::table('audit_events')
                ->where('id', $second->id)
                ->update(['payload_hash' => str_repeat('f', 64)]);
        } finally {
            DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_update');
        }

        $result = app(ChainVerifier::class)->verify($subjectId);

        expect($result->ok)->toBeFalse()
            ->and($result->brokenAtSequence)->toBe($second->sequence)
            ->and($result->reason)->toBe(ChainVerificationFailureReason::EVENT_HASH_MISMATCH)
            ->and($result->verifiedCount)->toBe(1);

        // Untouched rows before the tampered one are unaffected; the third row, chained from the
        // (now invalid) second, is never reached because verification stops at the break.
        expect($first->sequence)->toBe(1)
            ->and($third->sequence)->toBe(3);
    },
);

test('a chain that was never written to verifies as trivially ok', function (): void {
    $result = app(ChainVerifier::class)->verify(auditSubjectId());

    expect($result->ok)->toBeTrue()
        ->and($result->verifiedCount)->toBe(0);
});

test('hashPayload is a stable, reusable canonical hash for callers', function (): void {
    $service = app(AuditChainService::class);

    $payload = ['document_id' => 'doc-1', 'status' => 'READY'];

    expect($service->hashPayload($payload))->toBe($service->hashPayload($payload))
        ->and($service->hashPayload($payload))->toHaveLength(64);
});
