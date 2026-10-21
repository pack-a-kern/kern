<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Subjects\SubjectMembershipUniquenessTest;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * The `subject_memberships` partial unique index is Postgres-only (see that migration's own
 * docblock — matching the precedent `docs/handoff/006-audit-chain.md`'s append-only trigger test
 * set for a Postgres-specific database feature), so this self-skips under sqlite exactly like
 * `tests/Feature/Audit/AuditChainServiceTest.php` does.
 */
beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires a real Postgres connection (DB_CONNECTION=pgsql).');
    }

    Artisan::call('migrate', ['--force' => true]);
});

it('rejects a second active membership for the same (subject, user) pair', function (): void {
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::VIEWER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    $duplicate = fn () => SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::ADMIN,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    expect($duplicate)->toThrow(QueryException::class);
});

it('allows a new active membership after the previous one for the same pair was revoked', function (): void {
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    $first = SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::VIEWER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    $first->revoked_at = now('UTC');
    $first->save();

    $second = SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::ADMIN,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    expect($second->id)->not->toBe($first->id);
});
