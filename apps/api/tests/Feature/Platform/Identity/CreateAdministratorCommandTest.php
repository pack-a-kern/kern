<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Kern\Platform\Identity\Enums\UserStatus;
use Kern\Platform\Identity\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    // The command refuses to assign a role that has no row, so the catalogue has to exist first —
    // which is the ordering the installer follows too: `kern:access:sync` before `kern:admin:create`.
    Artisan::call('kern:access:sync');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * A password long enough for `Password::min(12)` and not in any breach corpus.
 *
 * `->uncompromised()` reaches Have I Been Pwned over the network. Laravel's verifier treats an
 * unreachable API as "not compromised", so this passes with or without egress — but a password that
 * is genuinely in the corpus would fail the suite only on machines that *have* egress, which is the
 * kind of test that passes locally and breaks CI.
 */
const ADMIN_PASSWORD = 'tz7-Quiet-Harbour-Lantern-41';

/**
 * `$this->artisan()` is typed `PendingCommand|int`, so it needs narrowing before anything can be
 * asserted on it. Once, here, rather than the same annotation at every call site — the same shape
 * `PruneExpiredSessionsTest` uses.
 *
 * @param  array<string, mixed>  $options
 */
function createAdmin(TestCase $test, string $email, array $options = []): PendingCommand
{
    $run = $test->artisan('kern:admin:create', ['email' => $email] + $options);

    /** @var PendingCommand $run */
    return $run;
}

/**
 * Answers both password prompts with the same string.
 */
function answeringWith(PendingCommand $run, string $password): PendingCommand
{
    return $run
        ->expectsQuestion('A password for this account (at least 12 characters)', $password)
        ->expectsQuestion('The same password again', $password);
}

it('creates the account, verifies the address and grants the role', function (): void {
    answeringWith(createAdmin($this, 'first@example.org'), ADMIN_PASSWORD)
        ->assertExitCode(0);

    $user = User::whereEmail('first@example.org')->first();

    expect($user)->not->toBeNull();
    assert($user instanceof User);

    expect($user->status)->toBe(UserStatus::ACTIVE)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->getRoleNames()->all())->toBe(['SUPER_ADMIN'])
        ->and(Hash::check(ADMIN_PASSWORD, (string) $user->password_hash))->toBeTrue();
});

it('grants a role other than SUPER_ADMIN when asked', function (): void {
    answeringWith(createAdmin($this, 'second@example.org', ['--role' => 'ADMIN']), ADMIN_PASSWORD)
        ->assertExitCode(0);

    expect(User::whereEmail('second@example.org')->first()?->getRoleNames()->all())->toBe(['ADMIN']);
});

it('leaves the address unverified when told to', function (): void {
    answeringWith(createAdmin($this, 'third@example.org', ['--unverified' => true]), ADMIN_PASSWORD)
        ->assertExitCode(0);

    expect(User::whereEmail('third@example.org')->first()?->email_verified_at)->toBeNull();
});

it('records the creation with no actor, because nobody signed in to do it', function (): void {
    answeringWith(createAdmin($this, 'audited@example.org'), ADMIN_PASSWORD)
        ->assertExitCode(0);

    $user = User::whereEmail('audited@example.org')->first();
    assert($user instanceof User);

    $event = DB::table('audit_events')
        ->where('event_type', 'AdministratorCreatedFromConsole')
        ->where('resource_id', $user->id)
        ->first();

    expect($event)->not->toBeNull();
    assert($event instanceof stdClass);

    expect($event->actor_id)->toBeNull()
        ->and($event->resource_type)->toBe('User');
});

/*
 * `--stdin` has no test here on purpose.
 *
 * The command reads PHP's `STDIN` constant, and under the test runner that is the runner's own
 * standard input: `fgets()` on it never sees end-of-input and the suite hangs rather than fails.
 * Routing the read through Symfony's streamable input to make it injectable would add an
 * abstraction whose only caller is a test. It is verified by hand against a running container
 * instead — `printf '%s\n' "$password" | docker compose exec -T app php artisan kern:admin:create
 * --stdin <email>` — and what it does after reading the line is the same validation every path
 * above already covers.
 */

it('refuses a role that is not in the catalogue', function (): void {
    createAdmin($this, 'nope@example.org', ['--role' => 'WIZARD'])->assertExitCode(1);

    expect(User::whereEmail('nope@example.org')->exists())->toBeFalse();
});

it('refuses a role the catalogue declares but the database has not got yet', function (): void {
    // The ordering trap the command exists to catch: a declared role with no row cannot be
    // assigned, and creating just that one row here would leave an installation holding a role and
    // none of its permissions.
    DB::table('model_has_roles')->delete();
    DB::table('roles')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    createAdmin($this, 'early@example.org')->assertExitCode(1);

    expect(User::whereEmail('early@example.org')->exists())->toBeFalse();
});

it('refuses an address that is not an address', function (): void {
    createAdmin($this, 'not-an-email')->assertExitCode(1);
});

it('refuses a second account for an address that already has one', function (): void {
    User::createWithEmail('taken@example.org', Hash::make(ADMIN_PASSWORD));

    createAdmin($this, 'taken@example.org')->assertExitCode(1);

    expect(User::query()->count())->toBe(1);
});

it('refuses a password shorter than the rule the application itself enforces', function (): void {
    answeringWith(createAdmin($this, 'weak@example.org'), 'short')->assertExitCode(1);

    expect(User::whereEmail('weak@example.org')->exists())->toBeFalse();
});

it('refuses two passwords that do not match', function (): void {
    createAdmin($this, 'mismatch@example.org')
        ->expectsQuestion('A password for this account (at least 12 characters)', ADMIN_PASSWORD)
        ->expectsQuestion('The same password again', ADMIN_PASSWORD . '-but-different')
        ->assertExitCode(1);

    expect(User::whereEmail('mismatch@example.org')->exists())->toBeFalse();
});

it('leaves nothing behind when the password is refused', function (): void {
    answeringWith(createAdmin($this, 'rolled-back@example.org'), 'short')->assertExitCode(1);

    // The password is read before the transaction opens, so a refusal writes nothing at all —
    // no user, and no audit row claiming one was made.
    expect(User::query()->count())->toBe(0)
        ->and(DB::table('audit_events')->where('event_type', 'AdministratorCreatedFromConsole')->count())->toBe(0);
});
