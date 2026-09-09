<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Support\Crypto\BlindIndex\BlindIndexHasher;
use App\Support\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use App\Support\KeyManagement\Contracts\KeyManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * Database isolation, applied per-directory rather than globally — deliberately.
 *
 * These suites insert fixed rows (an `fx_sources` row for BNR, a source registry entry, …) and
 * relied on sqlite handing every test a brand-new in-memory database. Against a real Postgres the
 * rows persist and the next test collides with `UniqueConstraintViolationException`. RefreshDatabase
 * wraps each test in a transaction that is rolled back afterwards, which fixes that.
 *
 * It is scoped, NOT applied to all of Feature/, because `Feature/Domain/Audit` forks OS processes
 * (`AuditChainConcurrencyTest`) that connect independently and must see *committed* rows — a
 * suite-wide transaction would make those children see an empty table and would silently destroy
 * the strongest concurrency guarantee in the codebase. See docs/handoff/015-m1-integration-repairs.md.
 */
uses(RefreshDatabase::class)->in('Feature/Domain/Fx');
uses(RefreshDatabase::class)->in('Feature/Domain/TaxKnowledge');
uses(RefreshDatabase::class)->in('Feature/Domain/Subjects');
uses(RefreshDatabase::class)->in('Feature/Domain/Identity');
uses(RefreshDatabase::class)->in('Feature/Domain/TaxEngine');
uses(RefreshDatabase::class)->in('Feature/Domain/Wallet');
uses(RefreshDatabase::class)->in('Feature/Domain/Documents');
uses(RefreshDatabase::class)->in('Feature/Domain/Rental');
uses(RefreshDatabase::class)->in('Feature/Domain/Declarations');
uses(RefreshDatabase::class)->in('Feature/Domain/Spv');
uses(RefreshDatabase::class)->in('Feature/Domain/Settings');
uses(RefreshDatabase::class)->in('Feature/Domain/Operations');

/**
 * Swaps the real OpenBao-backed `KeyManagementService` and the config-driven `BlindIndexHasher`
 * for fakes with a random in-process key, exactly the pattern
 * `tests/Feature/Domain/Subjects/BlindIndexLookupTest.php` established first. Every `Feature/
 * Domain/Identity` test needs this: `App\Domain\Identity\Models\User::createWithEmail()` and
 * `App\Domain\Identity\Models\UserMfaMethod`'s encrypted TOTP secret both go through
 * `App\Support\Crypto\Casts\Encrypted`, which resolves a real `KeyManagementService` — no live
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
 * `App\Domain\TaxKnowledge\Concerns\ForbidsAgentApproval` makes `status = APPROVED`
 * unreachable through Eloquent's `save()` on `TaxRuleVersion`/`FiscalParameterVersion` — by
 * design, per `docs/AGENTS.md` §9 ("no agent may set APPROVED"). `App\Domain\TaxEngine`'s own
 * tests still need to exercise the "an already-approved version backs a FINAL assessment" path,
 * so this helper inserts the row via the raw query builder — which never fires an Eloquent model
 * event and is therefore unaffected by that guard — simulating exactly what a future human-review
 * workflow this milestone does not build would eventually produce. No `App\Domain\TaxEngine` (or
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
 * @param array<string, mixed> $overrides
 */
function insertApprovedFiscalParameterVersion(string $fiscalParameterId, string $sourceSectionId, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('fiscal_parameter_versions')->insert(array_merge([
        'id' => $id,
        'fiscal_parameter_id' => $fiscalParameterId,
        'valid_from' => '2026-01-01',
        'valid_until' => null,
        'value_json' => json_encode(['value' => '0.10'], JSON_THROW_ON_ERROR),
        'source_section_id' => $sourceSectionId,
        'status' => 'APPROVED',
        'approved_by' => null,
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
    Artisan::call('taxos:access:sync');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->create();
    $user->assignRole('SUPER_ADMIN');

    return $user;
}

function ordinaryUser(): User
{
    return User::factory()->create();
}
