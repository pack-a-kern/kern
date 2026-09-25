<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Models\AuditEvent;
use Kern\Audit\Services\AuditChainService;
use Kern\Audit\Services\ChainVerifier;

/**
 * The chain works in an application that has nothing else — no users, no tenants, no tax.
 *
 * Every test below passes in the product's own suite as well, which is exactly why they are here: the
 * product has `users` and `tax_subjects`, so a migration that required them looked correct for as
 * long as nobody tried the package alone. Nobody had.
 */
it('creates its own tables with no identity tables anywhere', function (): void {
    expect(Schema::hasTable('audit_events'))->toBeTrue()
        ->and(Schema::hasTable('audit_chain_heads'))->toBeTrue()
        // The proof that this is a bare installation: the tables the foreign keys used to demand.
        ->and(Schema::hasTable('users'))->toBeFalse()
        ->and(Schema::hasTable('tax_subjects'))->toBeFalse();
});

it('records events and verifies the chain they form', function (): void {
    $audit = app(AuditChainService::class);
    $chain = (string) Str::uuid7();

    foreach (['Created', 'Changed', 'Deleted'] as $what) {
        $audit->record(AuditEventInput::occurringNow(
            subjectId: $chain,
            actorId: null,
            eventType: "Thing{$what}",
            resourceType: 'Thing',
            resourceId: $chain,
            payloadHash: $audit->hashPayload(['what' => $what]),
        ));
    }

    $result = app(ChainVerifier::class)->verify($chain);

    expect($result->ok)->toBeTrue()
        ->and(AuditEvent::query()->where('chain_key', $chain)->count())->toBe(3);
});

it('reports the exact sequence where a tampered row breaks the chain', function (): void {
    $audit = app(AuditChainService::class);
    $chain = (string) Str::uuid7();

    foreach (['one', 'two', 'three'] as $what) {
        $audit->record(AuditEventInput::occurringNow(
            subjectId: $chain,
            actorId: null,
            eventType: 'Thing',
            resourceType: 'Thing',
            resourceId: $what,
            payloadHash: $audit->hashPayload(['what' => $what]),
        ));
    }

    // The triggers refuse an UPDATE outright, which is the real defence. Disabling them is what a
    // tamperer with database access would have to do first, and the chain still notices afterwards —
    // which is the whole reason the hash chain exists on top of the triggers.
    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update');
    DB::table('audit_events')->where('chain_key', $chain)->where('sequence', 2)
        ->update(['resource_id' => 'something else']);
    DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_update');

    $result = app(ChainVerifier::class)->verify($chain);

    expect($result->ok)->toBeFalse()
        ->and($result->brokenAtSequence)->toBe(2);
});

it('refuses a mutation at the database, not merely in the model', function (): void {
    $audit = app(AuditChainService::class);
    $chain = (string) Str::uuid7();

    $audit->record(AuditEventInput::occurringNow(
        subjectId: $chain,
        actorId: null,
        eventType: 'Thing',
        resourceType: 'Thing',
        resourceId: $chain,
        payloadHash: $audit->hashPayload([]),
    ));

    expect(fn () => DB::table('audit_events')->where('chain_key', $chain)->update(['event_type' => 'Other']))
        ->toThrow(Illuminate\Database\QueryException::class)
        ->and(fn () => DB::table('audit_events')->where('chain_key', $chain)->delete())
        ->toThrow(Illuminate\Database\QueryException::class);
});
