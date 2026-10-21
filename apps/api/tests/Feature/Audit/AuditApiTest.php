<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

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

it('pages by number over a window frozen at the first request', function (): void {
    // 30 events, page size 25 — the second page must continue exactly where the first stopped and
    // must not repeat a row.
    seedAuditChain(30);

    $admin = settingsAdminUser();

    $first = $this->actingAs($admin)->getJson('/api/v1/audit/events')->assertOk();

    expect($first->json('total'))->toBe(30)
        ->and($first->json('page'))->toBe(1)
        ->and($first->json('per_page'))->toBe(25)
        ->and($first->json('anchor'))->toBeString();

    $anchor = $first->json('anchor');

    // Appended *after* the anchor was taken: it must not appear, and must not shift page 2 — the
    // failure mode a bare OFFSET has on an append-only table.
    seedAuditChain(1);

    $second = $this->actingAs($admin)
        ->getJson('/api/v1/audit/events?page=2&anchor=' . urlencode(is_string($anchor) ? $anchor : ''))
        ->assertOk();

    /** @var list<array<string, mixed>> $firstPage */
    $firstPage = $first->json('events');
    /** @var list<array<string, mixed>> $secondPage */
    $secondPage = $second->json('events');

    expect($firstPage)->toHaveCount(25)
        ->and($secondPage)->toHaveCount(5)
        ->and($second->json('total'))->toBe(30);

    /** @var list<string> $ids */
    $ids = array_map(
        static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
        array_merge(array_column($firstPage, 'id'), array_column($secondPage, 'id')),
    );
    expect(array_unique($ids))->toHaveCount(30);
});

it('honours the page size the pager asks for', function (): void {
    seedAuditChain(12);

    $response = $this->actingAs(settingsAdminUser())
        ->getJson('/api/v1/audit/events?per_page=10')
        ->assertOk();

    expect($response->json('events'))->toHaveCount(10)
        ->and($response->json('total'))->toBe(12)
        ->and($response->json('per_page'))->toBe(10);
});

it('rejects a page size the pager does not offer', function (): void {
    // Otherwise a crafted request decrypts nothing but does load the whole table into one response.
    $this->actingAs(settingsAdminUser())
        ->getJson('/api/v1/audit/events?per_page=5000')
        ->assertStatus(422);
});

it('clamps a page past the end back onto the last page with rows', function (): void {
    seedAuditChain(3);

    $response = $this->actingAs(settingsAdminUser())
        ->getJson('/api/v1/audit/events?page=99')
        ->assertOk();

    expect($response->json('page'))->toBe(1)
        ->and($response->json('events'))->toHaveCount(3);
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

/*
 * `audit.view` reaches the account and system lines; the lines on a taxpayer's chain need
 * `audit.view.taxpayers` too, which ADMIN does not hold as shipped.
 */

/** Appends `$count` events with no taxpayer — the system chain. */
function seedSystemEvents(int $count, string $eventType = 'ROLE_CHANGED'): void
{
    $service = app(AuditChainService::class);

    for ($i = 0; $i < $count; $i++) {
        $service->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: null,
            eventType: $eventType,
            resourceType: 'user',
            resourceId: null,
            payloadHash: $service->hashPayload(['i' => $i]),
        ));
    }
}

function auditAdministrator(): User
{
    settingsAdminUser();
    $admin = User::factory()->create();
    $admin->assignRole('ADMIN');

    return $admin;
}

it('shows an administrator the account and system lines, and no taxpayer’s', function (): void {
    $subjectId = seedAuditChain(3, 'DocumentUploaded');
    seedSystemEvents(2);
    $admin = auditAdministrator();

    $page = $this->actingAs($admin)->getJson('/api/v1/audit/events')->assertOk();
    /** @var list<array<string, mixed>> $events */
    $events = $page->json('events');

    expect($events)->toHaveCount(2)
        ->and(array_filter(array_column($events, 'subject_id'), static fn (mixed $id): bool => $id !== null))->toBe([])
        ->and($page->json('reach'))->toBe('accounts_and_system');

    // Asked for that taxpayer by id, it finds nothing — not even whether there is a trail.
    $this->actingAs($admin)->getJson('/api/v1/audit/events?subject_id=' . $subjectId)
        ->assertOk()
        ->assertJsonPath('total', 0);

    $summary = $this->actingAs($admin)->getJson('/api/v1/audit/summary')->assertOk();

    expect(auditInt($summary->json('events_total')))->toBe(2)
        ->and($summary->json('event_types'))->toBe(['ROLE_CHANGED'])
        ->and(auditInt($summary->json('integrity.chains_checked')))->toBe(1)
        ->and($summary->json('reach'))->toBe('accounts_and_system');
});

it('shows a super administrator every line', function (): void {
    seedAuditChain(3, 'DocumentUploaded');
    seedSystemEvents(2);
    $superAdmin = settingsAdminUser();

    $this->actingAs($superAdmin)->getJson('/api/v1/audit/events')
        ->assertOk()
        ->assertJsonPath('total', 5)
        ->assertJsonPath('reach', 'everything');

    $summary = $this->actingAs($superAdmin)->getJson('/api/v1/audit/summary')->assertOk();

    expect($summary->json('event_types'))->toContain('DocumentUploaded', 'ROLE_CHANGED')
        ->and(auditInt($summary->json('integrity.chains_checked')))->toBe(2);
});
