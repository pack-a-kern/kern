<?php

declare(strict_types=1);

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Subjects\Models\TaxSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * `tests/Pest.php` deliberately leaves `Feature/Domain/Audit` outside RefreshDatabase, because
 * `AuditChainConcurrencyTest` forks processes that must see *committed* rows. This file does not
 * fork, and its assertions are counts over the whole table, so it opts in per-file — without it
 * events from earlier tests leak in and every count is wrong.
 */
uses(RefreshDatabase::class);

/**
 * Appending to the chain takes a `SELECT … FOR UPDATE` row lock on the chain head, which sqlite
 * does not implement — the same gate `AuditChainConcurrencyTest` uses.
 */
beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped(
            'Requires a real Postgres connection (DB_CONNECTION=pgsql) — see docs/handoff/006-audit-chain.md.',
        );
    }

    bootstrapIdentityCryptoForTests();
});

/** `$response->json(...)` is `mixed`; these narrow it once so the assertions read plainly. */
function auditInt(mixed $value): int
{
    return is_numeric($value) ? (int) $value : -1;
}

/**
 * Appends `$count` events to one subject's chain and returns that subject id.
 */
function seedAuditChain(int $count, string $eventType = 'SETTINGS_UPDATED'): string
{
    // A real subject: `audit_chain_heads.subject_id` is a foreign key, so a chain cannot exist
    // for a subject that does not.
    $service = app(AuditChainService::class);
    $subjectId = (string) TaxSubject::factory()->create()->getKey();

    for ($i = 0; $i < $count; $i++) {
        $service->record(AuditEventInput::occurringNow(
            subjectId: $subjectId,
            actorId: null,
            eventType: $eventType,
            resourceType: 'setting',
            resourceId: 'ai.model',
            payloadHash: $service->hashPayload(['i' => $i]),
        ));
    }

    return $subjectId;
}

it('returns the newest events first', function (): void {
    seedAuditChain(3);

    $response = $this->actingAs(settingsAdminUser())
        ->getJson('/api/v1/audit/events')
        ->assertOk();

    /** @var list<array<string, mixed>> $events */
    $events = $response->json('events');

    expect($events)->toHaveCount(3)
        ->and(auditInt($events[0]['sequence']))->toBeGreaterThan(auditInt($events[1]['sequence']));
});

it('pages by chain sequence rather than by offset', function (): void {
    // 30 events, page size 25 — the second page must continue exactly where the first stopped and
    // must not repeat a row, which is the failure mode OFFSET has on an append-only table.
    seedAuditChain(30);

    $admin = settingsAdminUser();

    $first = $this->actingAs($admin)->getJson('/api/v1/audit/events')->assertOk();
    $cursor = $first->json('next_cursor');

    expect($cursor)->not->toBeNull();

    expect($cursor)->toBeInt();

    $second = $this->actingAs($admin)
        ->getJson('/api/v1/audit/events?cursor=' . (string) auditInt($cursor))
        ->assertOk();

    /** @var list<array<string, mixed>> $firstPage */
    $firstPage = $first->json('events');
    /** @var list<array<string, mixed>> $secondPage */
    $secondPage = $second->json('events');

    expect($firstPage)->toHaveCount(25)
        ->and($secondPage)->toHaveCount(5)
        ->and($second->json('next_cursor'))->toBeNull();

    /** @var list<string> $ids */
    $ids = array_map(
        static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
        array_merge(array_column($firstPage, 'id'), array_column($secondPage, 'id')),
    );
    expect(array_unique($ids))->toHaveCount(30);
});

it('filters by event type', function (): void {
    seedAuditChain(2, 'SETTINGS_UPDATED');
    seedAuditChain(1, 'DOCUMENT_VIEWED');

    $response = $this->actingAs(settingsAdminUser())
        ->getJson('/api/v1/audit/events?event_type=DOCUMENT_VIEWED')
        ->assertOk();

    /** @var list<array<string, mixed>> $events */
    $events = $response->json('events');

    expect($events)->toHaveCount(1)
        ->and($events[0]['event_type'])->toBe('DOCUMENT_VIEWED');
});

it('never returns a payload, only its hash', function (): void {
    seedAuditChain(1);

    $response = $this->actingAs(settingsAdminUser())->getJson('/api/v1/audit/events')->assertOk();

    /** @var array<string, mixed> $event */
    $event = $response->json('events.0');

    expect($event)->toHaveKey('payload_hash')
        ->and($event)->not->toHaveKey('payload')
        ->and($event['payload_hash'])->toBeString()
        ->and(strlen(is_scalar($event['payload_hash']) ? (string) $event['payload_hash'] : ''))->toBe(64);
});

it('verifies the chain for real rather than reading a stored flag', function (): void {
    seedAuditChain(4);

    $response = $this->actingAs(settingsAdminUser())->getJson('/api/v1/audit/summary')->assertOk();

    expect($response->json('integrity.ok'))->toBeTrue()
        ->and($response->json('integrity.events_verified'))->toBe(4)
        ->and($response->json('integrity.chains_checked'))->toBe(1)
        ->and($response->json('events_total'))->toBe(4);

});

it('offers only event types that actually occur', function (): void {
    seedAuditChain(1, 'SPV_SYNC');

    $response = $this->actingAs(settingsAdminUser())->getJson('/api/v1/audit/summary')->assertOk();

    expect($response->json('event_types'))->toBe(['SPV_SYNC']);
});

it('is closed to a user who is not a settings administrator', function (): void {
    seedAuditChain(1);

    $user = ordinaryUser();

    $this->actingAs($user)->getJson('/api/v1/audit/events')->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/audit/summary')->assertForbidden();
});
