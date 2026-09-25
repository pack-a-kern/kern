<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * Database isolation, applied per-directory rather than globally — deliberately.
 *
 * These suites insert fixed rows (an `ext_official_sources` row for BNR, a source registry entry, …) and
 * relied on sqlite handing every test a brand-new in-memory database. Against a real Postgres the
 * rows persist and the next test collides with `UniqueConstraintViolationException`. RefreshDatabase
 * wraps each test in a transaction that is rolled back afterwards, which fixes that.
 *
 * It is scoped, NOT applied to all of Feature/, because `Feature/Audit` forks OS processes
 * (`AuditChainConcurrencyTest`) that connect independently and must see *committed* rows — a
 * suite-wide transaction would make those children see an empty table and would silently destroy
 * the strongest concurrency guarantee in the codebase. See docs/handoff/015-m1-integration-repairs.md.
 */
uses(RefreshDatabase::class)->in('Feature/TaxRomania/Banks');
uses(RefreshDatabase::class)->in('Feature/TaxRomania/Fx');
uses(RefreshDatabase::class)->in('Feature/Legislation');
uses(RefreshDatabase::class)->in('Feature/TaxRomania/FiscalParameters');
uses(RefreshDatabase::class)->in('Feature/TaxRomania/Subjects');
uses(RefreshDatabase::class)->in('Feature/Platform/Identity');
uses(RefreshDatabase::class)->in('Feature/TaxRomania/TaxEngine');
uses(RefreshDatabase::class)->in('Feature/TaxRomania/Wallet');
uses(RefreshDatabase::class)->in('Feature/Documents');
uses(RefreshDatabase::class)->in('Feature/TaxRomania/Rental');
uses(RefreshDatabase::class)->in('Feature/TaxRomania/Declarations');
uses(RefreshDatabase::class)->in('Feature/TaxRomania/Spv');
uses(RefreshDatabase::class)->in('Feature/Platform/Settings');
uses(RefreshDatabase::class)->in('Feature/TaxRomania/Operations');
uses(RefreshDatabase::class)->in('Feature/TaxRomania/StatementImport');

/**
 * Puts the database back after the one suite that is allowed to commit.
 *
 * `Feature/Audit` is excluded from RefreshDatabase above, for a reason that is not
 * negotiable: `AuditChainConcurrencyTest` forks OS processes which open their own connections and
 * must therefore see *committed* rows. What that costs is that its writes survive the suite — and
 * they are not a handful of audit rows. Measured: 16 users, 11 tax subjects and 90 events left
 * behind, because every chain needs a subject and every actor needs an account.
 *
 * Pest walks directories alphabetically, so `Audit` runs first and every later suite then starts
 * against an installation that is not empty. That broke tests which had every right to assume it
 * was: "registration stays open on an instance with no users" found sixteen; the admin directory
 * answered 500 because those users' email ciphertext was sealed with a *previous* run's in-process
 * fake key; assertions counting audit events found ninety of somebody else's.
 *
 * `TRUNCATE` rather than `DELETE` on purpose. `audit_events` carries a `BEFORE UPDATE OR DELETE`
 * trigger that refuses both — that append-only guarantee is the point of the table and
 * `AuditChainServiceTest` asserts it — and `TRUNCATE` is neither operation, so the guarantee stays
 * fully enforced for the application while the harness can still reset its own fixtures. `CASCADE`
 * follows the foreign keys pointing at these tables, which is what makes the reset complete rather
 * than a new set of orphans.
 */
uses()->afterEach(function (): void {
    // sqlite has neither the trigger nor `TRUNCATE ... CASCADE`, and hands every test its own
    // in-memory database anyway, so there is nothing to reset there.
    if (DB::connection()->getDriverName() !== 'pgsql') {
        return;
    }

    DB::statement('TRUNCATE users, tax_subjects, audit_events, audit_chain_heads RESTART IDENTITY CASCADE');
})->in('Feature/Audit');

/**
 * Swaps the real OpenBao-backed `KeyManagementService` and the config-driven `BlindIndexHasher`
 * for fakes with a random in-process key, exactly the pattern
 * `tests/Feature/TaxRomania/Subjects/BlindIndexLookupTest.php` established first. Every `Feature/
 * Domain/Identity` test needs this: `Kern\Platform\Identity\Models\User::createWithEmail()` and
 * `Kern\Platform\Identity\Models\UserMfaMethod`'s encrypted TOTP secret both go through
 * `Kern\Platform\Crypto\Casts\Encrypted`, which resolves a real `KeyManagementService` — no live
 * OpenBao is reachable from the container this suite runs in. A random per-run blind-index key is
 * safe here (unlike production, ADR 0002) because no test needs its blind indexes to remain
 * stable across separate test runs.
 */
function bootstrapIdentityCryptoForTests(): void
{
    app()->instance(KeyManagementService::class, new InMemoryKeyManagementService());

    $provider = new ConfigBlindIndexKeyProvider(
        json_encode(['1' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR),
        1,
    );
    app()->instance(BlindIndexHasher::class, new BlindIndexHasher($provider));
}

/**
 * Laravel's `TestCase` does **not** automatically carry `Set-Cookie` headers from one simulated
 * HTTP call into the next — unlike a real browser. Two separate, both-necessary reasons, each
 * confirmed by reading `Illuminate\Foundation\Testing\Concerns\MakesHttpRequests` directly rather
 * than assumed, after each produced its own distinct failure mode empirically:
 *
 * 1. `$this->defaultCookies`/`$this->unencryptedCookies` are only ever populated by an explicit
 *    `withCookie()`/`withCookies()`/`withUnencryptedCookies()` call — nothing copies a response's
 *    `Set-Cookie` headers into the next request's cookie jar automatically.
 * 2. **`postJson()`/`getJson()`/`deleteJson()` (`MakesHttpRequests::json()`) send *no* cookies at
 *    all unless `withCredentials()` has been called first** — `json()` builds its request via
 *    `prepareCookiesForJsonRequest()`, which returns `[]` unless `$this->withCredentials` is true
 *    (mirroring a real `fetch()`'s default `credentials: 'omit'` for JSON requests). Every
 *    `it*Json()` helper in this test suite's Identity flows is affected; `withCredentials()` is
 *    idempotent, so calling it again on every carry is harmless.
 *
 * Without both fixes, every subsequent simulated request silently gets issued a brand-new,
 * cookie-less session — which still *looks* authenticated (the session `Store` and the `Auth`
 * guard are container singletons that keep their in-memory attributes across calls within one
 * test, regardless of id churn) while giving completely wrong answers to "is this the current
 * session id" style assertions. Session-lifecycle tests that issue more than one request and care
 * which session id the second request is on (device/session listing, fixation checks) must call
 * this after every response whose cookies matter.
 *
 * `$cookie->getValue()` is the already-`EncryptCookies`-encrypted string exactly as a browser
 * would replay it verbatim — passed via `withUnencryptedCookies()`, not `withCookie()`: the latter
 * treats its value as *plaintext* and has the test client encrypt it again
 * (`prepareCookiesForRequest()`), which would double-encrypt an already-encrypted value and make
 * the server recover garbage instead of the real session id.
 *
 * @template TResponse of \Symfony\Component\HttpFoundation\Response
 *
 * @param \Illuminate\Testing\TestResponse<TResponse> $response
 *
 * @return \Illuminate\Testing\TestResponse<TResponse>
 */
function carryCookiesFrom(\Illuminate\Testing\TestResponse $response): \Illuminate\Testing\TestResponse
{
    /**
     * Pest's own return type for test() (TestCall|HigherOrderTapProxy) describes its use
     * immediately after it()/describe(); called from inside a running test's own body — as here
     * — it returns that test's TestCase instance instead, which is why this cannot be expressed
     * as a plain @var narrowing (PHPStan correctly reports the declared type as unrelated).
     *
     * @var \Tests\TestCase $test
     *
     * @phpstan-ignore varTag.nativeType
     */
    $test = test();
    $test->withCredentials();

    foreach ($response->headers->getCookies() as $cookie) {
        $test->withUnencryptedCookies([$cookie->getName() => (string) $cookie->getValue()]);
    }

    return $response;
}

/**
 * `TestResponse::json()` returns `mixed` — every Identity test that pulls a TOTP secret, a
 * recovery code, or similar out of a JSON response body needs a real `non-empty-string` (e.g. to
 * pass to `OTPHP\TOTP::createFromSecret()`), not `mixed`. Throws rather than silently coercing, so
 * a genuinely missing/malformed key fails loudly at the point of use, not three lines later with a
 * confusing type error from an unrelated library.
 *
 * @param \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $response
 *
 * @return non-empty-string
 */
function jsonString(\Illuminate\Testing\TestResponse $response, string $key): string
{
    $value = $response->json($key);

    if (! is_string($value) || $value === '') {
        throw new RuntimeException("Expected a non-empty string at JSON key \"{$key}\".");
    }

    return $value;
}

/**
 * @param \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $response
 *
 * @return list<string>
 */
function jsonStringList(\Illuminate\Testing\TestResponse $response, string $key): array
{
    $value = $response->json($key);

    if (! is_array($value)) {
        throw new RuntimeException("Expected an array at JSON key \"{$key}\".");
    }

    return array_values(array_map(static function (mixed $item): string {
        if (! is_string($item)) {
            throw new RuntimeException('Expected every item to be a string.');
        }

        return $item;
    }, $value));
}

/**
 * `Kern\Platform\Approval\ForbidsAgentApproval` makes `status = APPROVED`
 * unreachable through Eloquent's `save()` on `TaxRuleVersion`/`FiscalParameterVersion` — by
 * design, per `docs/AGENTS.md` §9 ("no agent may set APPROVED"). `Kern\TaxRomania\TaxEngine`'s own
 * tests still need to exercise the "an already-approved version backs a FINAL assessment" path,
 * so this helper inserts the row via the raw query builder — which never fires an Eloquent model
 * event and is therefore unaffected by that guard — simulating exactly what a future human-review
 * workflow this milestone does not build would eventually produce. No `Kern\TaxRomania\TaxEngine` (or
 * any other) application code path ever does this itself; only test setup does.
 *
 * @param array<string, mixed> $overrides
 */
function insertApprovedTaxRuleVersion(string $taxRuleId, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('tax_rule_versions')->insert(array_merge([
        'id' => $id,
        'tax_rule_id' => $taxRuleId,
        'semantic_version' => '1.0.0',
        'valid_from' => '2026-01-01',
        'valid_until' => null,
        'implementation_class' => null,
        'config_json' => json_encode([], JSON_THROW_ON_ERROR),
        'status' => 'APPROVED',
        'source_hash' => null,
        'approved_at' => now('UTC'),
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ], $overrides));

    return $id;
}

/**
 * An approved value names the person who approved it — the database refuses one that does not
 * (`fiscal_parameter_versions_approved_by_a_person`) — so this one names a user made for it, unless
 * the test names its own.
 *
 * @param array<string, mixed> $overrides
 */
function insertApprovedFiscalParameterVersion(string $fiscalParameterId, string $sourceSectionId, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    if (! array_key_exists('approved_by', $overrides)) {
        $overrides['approved_by'] = User::factory()->create()->id;
    }

    DB::table('fiscal_parameter_versions')->insert(array_merge([
        'id' => $id,
        'fiscal_parameter_id' => $fiscalParameterId,
        'valid_from' => '2026-01-01',
        'valid_until' => null,
        'value_json' => json_encode(['value' => '0.10'], JSON_THROW_ON_ERROR),
        'source_section_id' => $sourceSectionId,
        'status' => 'APPROVED',
        'approved_at' => now('UTC'),
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ], $overrides));

    return $id;
}

/**
 * A user who may administer the platform.
 *
 * Access is a real Spatie role now (`config/access.php`), not the `SETTINGS_ADMIN_USER_IDS`
 * allowlist ADR 0013 introduced — see docs/adr/0014. The catalogue is synced first because the
 * roles table starts empty in a freshly migrated test database, and `assignRole` on a role that
 * does not exist throws rather than silently granting nothing.
 */
function settingsAdminUser(): User
{
    Artisan::call('kern:access:sync');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');

    return $user;
}

function ordinaryUser(): User
{
    return User::factory()->create();
}

/**
 * A structurally valid CNP, with the check digit computed for the twelve digits given.
 *
 * Fixtures are built rather than borrowed: a real CNP in a test file is a real person's identifier
 * in version control. Shared here because both the parser's tests and the registration tests need
 * numbers the parser will accept.
 */
function cnpWithCheckDigit(string $first12): string
{
    $key = '279146358279';
    $sum = 0;

    for ($index = 0; $index < 12; $index++) {
        $sum += (int) $first12[$index] * (int) $key[$index];
    }

    $remainder = $sum % 11;

    return $first12 . ($remainder === 10 ? '1' : (string) $remainder);
}

/**
 * The directory a package ships, found through any class inside it.
 *
 * Tests that scan source — "no float anywhere in Rental", "no impure rule in TaxEngine" — need a
 * path, and a path written down is a test that silently scans nothing the next time the code moves.
 * Walking up from the class's own file to its `composer.json` cannot be wrong about where it lives.
 */
/** @param class-string $class */
function packageRoot(string $class): string
{
    $file = (new ReflectionClass($class))->getFileName();

    if ($file === false) {
        throw new RuntimeException("Cannot locate {$class}: no file.");
    }

    $directory = dirname($file);

    while (! is_file($directory . '/composer.json')) {
        $parent = dirname($directory);

        if ($parent === $directory) {
            throw new RuntimeException("Cannot locate the package holding {$class}.");
        }

        $directory = $parent;
    }

    return $directory;
}
