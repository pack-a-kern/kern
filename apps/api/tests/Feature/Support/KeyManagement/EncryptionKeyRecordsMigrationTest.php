<?php

declare(strict_types=1);

namespace Tests\Feature\Support\KeyManagement\EncryptionKeyRecordsMigrationTest;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

/**
 * A unique identifier per test, so these tests do not depend on starting from an empty table.
 *
 * They originally reused a fixed identifier, which passed only because sqlite hands each test a
 * fresh in-memory database; against a real Postgres the rows persisted and the second test
 * collided with the first. Global RefreshDatabase is deliberately NOT used here: it wraps each
 * test in a transaction, and the audit concurrency test forks processes that must see committed
 * rows, so a suite-wide transaction would break it.
 */
function kekIdentifier(): string
{
    return 'tax-subject-kek-' . Str::lower((string) Str::ulid());
}

it('creates the encryption_key_records table with the expected columns', function (): void {
    expect(Schema::hasTable('encryption_key_records'))->toBeTrue()
        ->and(Schema::hasColumns('encryption_key_records', [
            'id', 'key_class', 'identifier', 'version', 'status',
            'activated_at', 'retired_at', 'notes', 'created_at', 'updated_at',
        ]))->toBeTrue();
});

it('enforces uniqueness of (key_class, identifier, version)', function (): void {
    $identifier = kekIdentifier();
    $insert = fn () => DB::table('encryption_key_records')->insert([
        'id' => (string) Str::ulid(),
        'key_class' => 'KEK',
        'identifier' => $identifier,
        'version' => 1,
        'status' => 'ACTIVE',
        'activated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $insert();

    expect($insert)->toThrow(QueryException::class);
});

it('allows the same identifier at a different version', function (): void {
    $identifier = kekIdentifier();
    DB::table('encryption_key_records')->insert([
        'id' => (string) Str::ulid(),
        'key_class' => 'KEK',
        'identifier' => $identifier,
        'version' => 1,
        'status' => 'RETIRED',
        'activated_at' => now(),
        'retired_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('encryption_key_records')->insert([
        'id' => (string) Str::ulid(),
        'key_class' => 'KEK',
        'identifier' => $identifier,
        'version' => 2,
        'status' => 'ACTIVE',
        'activated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('encryption_key_records')->where('identifier', $identifier)->count())->toBe(2);
});

it('rejects a key_class outside the enum', function (): void {
    $insert = fn () => DB::table('encryption_key_records')->insert([
        'id' => (string) Str::ulid(),
        'key_class' => 'NOT_A_REAL_CLASS',
        'identifier' => 'x',
        'version' => 1,
        'status' => 'ACTIVE',
        'activated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($insert)->toThrow(QueryException::class);
});
