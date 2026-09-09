<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Subjects\SubjectMembershipUniquenessTest;

use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Support\Crypto\BlindIndex\BlindIndexHasher;
use App\Support\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use App\Support\KeyManagement\Contracts\KeyManagementService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\InMemoryKeyManagementService;

/**
 * The `subject_memberships` partial unique index is Postgres-only (see that migration's own
 * docblock — matching the precedent `docs/handoff/006-audit-chain.md`'s append-only trigger test
 * set for a Postgres-specific database feature), so this self-skips under sqlite exactly like
 * `tests/Feature/Domain/Audit/AuditChainServiceTest.php` does.
 */
beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires a real Postgres connection (DB_CONNECTION=pgsql).');
    }

    Artisan::call('migrate', ['--force' => true]);
    app()->instance(KeyManagementService::class, new InMemoryKeyManagementService());

    $blindIndexProvider = new ConfigBlindIndexKeyProvider(
        json_encode(['1' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR),
        1,
    );
    app()->instance(BlindIndexHasher::class, new BlindIndexHasher($blindIndexProvider));
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
