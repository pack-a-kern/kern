<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Testing\TestResponse;
use Kern\Legislation\Jobs\ReadPortalSearchPage;
use Kern\Legislation\Legislatie\PortalSearch;
use Kern\Legislation\Models\SourceSection;
use Kern\Platform\Access\SuperAdministratorRole;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\Tenancy\TenantContext;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\FiscalParameters\Enums\FiscalParameterValueType;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameter;
use Kern\TaxRomania\Fx\Models\FxRate;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\TaxEngine\Contracts\LedgerIncomeProvider;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\LedgerIncomeEntry;
use Kern\TaxRomania\TaxRules\TaxRuleVersion;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * The in-memory key manager, for every feature test that does not bind its own.
 *
 * A default rather than something each suite remembers: forty-nine tests across the tax engine,
 * settings, FX and documents never did, resolved the real OpenBao binding, and failed on its missing
 * token in every run of `make test` — not on what they were written to check. A test that wants the
 * real binding builds it itself (the OpenBao client tests) or forgets this instance first
 * (`ProbeSystemHealthTest`'s "cannot even be built"). A suite's own `beforeEach` runs after this one,
 * so it can still put its own fake in place.
 */
uses()->beforeEach(function (): void {
    app()->instance(KeyManagementService::class, new InMemoryKeyManagementService());
})->in('Feature');

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
uses(RefreshDatabase::class)->in('Feature/Investments');
uses(RefreshDatabase::class)->in('Feature/TaxRomania/FiscalParameters');
uses(RefreshDatabase::class)->in('Feature/TaxRomania/Subjects');
uses(RefreshDatabase::class)->in('Feature/Platform/Identity');
uses(RefreshDatabase::class)->in('Feature/Platform/Security');
uses(RefreshDatabase::class)->in('Feature/TaxRomania/TaxEngine');
uses(RefreshDatabase::class)->in('Feature/TaxRomania/Wallet');
uses(RefreshDatabase::class)->in('Feature/Documents');
uses(RefreshDatabase::class)->in('Feature/TaxRomania/Rental');
uses(RefreshDatabase::class)->in('Feature/TaxRomania/Declarations');
uses(RefreshDatabase::class)->in('Feature/TaxRomania/Spv');
uses(RefreshDatabase::class)->in('Feature/Platform/Settings');
uses(RefreshDatabase::class)->in('Feature/Platform/Notifications');
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

    // And the database has to be one the suite owns. This hook keyed off the driver alone, which
    // made it "any Postgres this process happens to be pointed at" — including a development
    // database, whose accounts and tax subjects it emptied. `TestCase` refuses such a database, but
    // it refuses it by throwing from `setUp()`, and an `afterEach` runs anyway: the test failed and
    // the truncate went ahead regardless. Asking the same question here is what makes the refusal
    // mean something.
    if (! TestCase::isTestDatabase()) {
        return;
    }

    DB::statement('TRUNCATE users, tax_subjects, audit_events, audit_chain_heads RESTART IDENTITY CASCADE');
})->in('Feature/Audit');

/**
 * Marks the current test session as having just proved its second factor, so a route behind
 * `verify-recent` lets the request through.
 *
 * Needed because `actingAs()` sets the guard's user and nothing else. It writes the same session key
 * `Kern\Platform\Identity\Support\StepUpPolicy` reads, through the Store the next request will use —
 * a container singleton within one test, which is why this survives into it even though
 * `postJson()` sends no cookies (see `carryCookiesFrom()` below).
 *
 * A test that wants the *refusal* simply does not call this.
 */
function verifySession(): void
{
    app(\Kern\Platform\Identity\Support\StepUpPolicy::class)->markVerified(testSessionStore());
}

/**
 * Withdraws this session's step-up verification, so a test can assert the gate refusing.
 *
 * Needed because the session Store is a container singleton that keeps its attributes across calls within one
 * test — and, in a full-suite run, a marker written by an earlier test reaches a later one. A test asserting
 * `VERIFICATION_REQUIRED` then passed alone and failed in the suite, which is the worst way for this to be
 * wrong.
 */
function forgetVerification(): void
{
    testSessionStore()->forget('auth.step_up_verified_at');
}

/**
 * The session Store behind the `session()` manager, typed.
 *
 * `session()` returns the manager, whose `driver()` is documented as returning a `Session` but is
 * typed loosely enough that every caller would otherwise narrow it itself.
 */
/**
 * An authenticator enrolled for `$user`, so `verify-recent` has a factor to ask for. A test user has no
 * password and no factor — an account the gate can ask nothing of, and lets through — so a test asserting
 * the refusal gives it one first. Made-up secret.
 */
function withAuthenticator(User $user): User
{
    \Kern\Platform\Identity\Models\UserMfaMethod::query()->create([
        'user_id' => $user->id,
        'type' => \Kern\Platform\Identity\Enums\MfaMethodType::TOTP,
        'secret_ciphertext' => 'JBSWY3DPEHPK3PXP',
        'confirmed_at' => now('UTC'),
    ]);

    return $user;
}

function testSessionStore(): \Illuminate\Contracts\Session\Session
{
    $store = session()->driver();

    expect($store)->toBeInstanceOf(\Illuminate\Contracts\Session\Session::class);

    /** @var \Illuminate\Contracts\Session\Session $store */
    return $store;
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
    $user->assignRole(SuperAdministratorRole::NAME);

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

/**
 * A fixture file that belongs to a package, reached through the package.
 *
 * The captured BNR feed lives with `kern/tax-romania`, which parses it, and the archived
 * consolidations live with `kern/legislation`. An application test may read them; the reverse — a
 * package reaching into `apps/api/tests` — is what the split exists to prevent.
 *
 * @param class-string $class any class from the package that owns the fixture
 */
function fixtureFromPackage(string $class, string $path): string
{
    return packageRoot($class) . '/tests/Fixtures/' . ltrim($path, '/');
}

/**
 * The parameters the three real rules read, with values this test chose.
 *
 * Real rows rather than a stub, because `ParameterResolver` is part of what is under test here — it is the
 * step that turns "this rule needs RO.MIN_WAGE" into a value valid on a date. The numbers are the test's own:
 * 1,000 per minimum wage makes the thresholds 6,000 / 12,000 / 24,000 and keeps every assertion checkable.
 */
function seedRuleParameters(): void
{
    // The type is on the parameter, not the version, and the accessors check it — asking a MONEY parameter for
    // a decimal rate is refused rather than coerced, which is why each one states its own.
    $values = [
        'RO.MIN_WAGE' => [FiscalParameterValueType::MONEY, ['amount_minor' => 100000, 'currency' => 'RON']],
        'RO.CASS.RATE' => [FiscalParameterValueType::DECIMAL, ['value' => '0.10']],
        'RO.CASS.OTHER_INCOME_BANDS_WAGES' => [FiscalParameterValueType::JSON, ['wages' => [6, 12, 24]]],
        'RO.INCOME_TAX.RATE' => [FiscalParameterValueType::DECIMAL, ['value' => '0.10']],
        'RO.INCOME_TAX.RENT_EXPENSE_QUOTA' => [FiscalParameterValueType::DECIMAL, ['value' => '0.20']],
        // Independent activity owes two contributions on the same income, computed two different ways: the
        // pension one steps up at thresholds, the health one sits between a floor and a cap.
        'RO.CAS.RATE' => [FiscalParameterValueType::DECIMAL, ['value' => '0.25']],
        'RO.CAS.BASE_MIN_WAGES' => [FiscalParameterValueType::JSON, ['wages' => [12, 24]]],
        'RO.CASS.INDEPENDENT_BASE_WAGES' => [FiscalParameterValueType::JSON, ['floor' => 6, 'cap' => 72]],
        // Public debt left out of the CASS ceiling and not taxed, as the law says (art. 170 (5), art. 93 (1) a)).
        'RO.CASS.PUBLIC_DEBT_EXCLUDED' => [FiscalParameterValueType::JSON, ['value' => true]],
        'RO.INVESTMENTS.PUBLIC_DEBT_EXEMPT' => [FiscalParameterValueType::JSON, ['value' => true]],
    ];

    foreach ($values as $code => [$valueType, $valueJson]) {
        $parameter = FiscalParameter::factory()->create(['code' => $code, 'value_type' => $valueType->value]);

        /*
         * Through the suite's own helper, which inserts the row directly.
         *
         * Setting APPROVED through the model is refused — `AgentApprovalNotPermittedException`, because
         * approving a fiscal value is a human act and application code may not perform it. A test needs an
         * approved value as a *precondition* rather than as an action, and the helper exists for exactly that.
         *
         * Approved matters here beyond realism: only an approved version's source section may be cited by a
         * rule, since a candidate's is a section nobody has agreed on yet.
         */
        insertApprovedFiscalParameterVersion(
            $parameter->id,
            SourceSection::factory()->create()->id,
            ['valid_from' => '2026-01-01', 'value_json' => json_encode($valueJson, JSON_THROW_ON_ERROR)],
        );
    }
}

/**
 * A subject with a tenant bound, because every table here is tenant-scoped.
 *
 * The scope is the thing that keeps one taxpayer's figures away from another's, so a test that bypassed it
 * would be testing a query no request can make.
 */
/**
 * The owner of the subject under test, remembered so nothing has to guess at it.
 *
 * `User::query()->firstOrFail()` used to stand in for this, and `insertApprovedFiscalParameterVersion` creates a
 * user of its own for `approved_by` — five of them — so the test authenticated as whichever row came back first.
 * On sqlite that happened to be the owner; on Postgres it was not, so a test asserting the step-up gate refuses
 * instead found a user with no authenticator and watched the request sail through. It passed alone and failed in
 * the suite, which is the worst shape a test failure takes.
 */
function subjectOwner(): User
{
    /** @var User $user */
    $user = User::query()->findOrFail($GLOBALS['assessableOwnerId']);

    return $user;
}

function assessableSubject(): TaxSubject
{
    $subject = TaxSubject::factory()->create();
    $user = User::factory()->create();
    $GLOBALS['assessableOwnerId'] = $user->id;

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    app(TenantContext::class)->authorizeAndActivate($user->id, $subject->id);

    return $subject;
}

/** @param list<LedgerIncomeEntry> $entries */
function fakeLedger(array $entries): void
{
    app()->instance(LedgerIncomeProvider::class, new class ($entries) implements LedgerIncomeProvider {
        /** @param list<LedgerIncomeEntry> $entries */
        public function __construct(
            /** @var list<LedgerIncomeEntry> */
            private array $entries,
        ) {
        }

        /** @return list<int> */
        public function yearsWithActivity(string $taxSubjectId): array
        {
            // The fake holds entries, not transactions, so the years it can offer are the years it was given.
            return array_values(array_unique(array_map(
                static fn (LedgerIncomeEntry $entry): int => (int) $entry->bookedAt->format('Y'),
                $this->entries,
            )));
        }

        public function incomeIn(string $taxSubjectId, int $fiscalYear, array $categoryCodes): array
        {
            return array_values(array_filter(
                $this->entries,
                static fn (LedgerIncomeEntry $entry): bool => in_array($entry->categoryCode, $categoryCodes, true),
            ));
        }
    });
}

/**
 * Approves every registered rule version, as a person would.
 *
 * A `FINAL` assessment refuses a candidate rule, which is the point of the status — so a test about final
 * assessments has to get past it the way the application does, through `approveByPerson`. Setting `APPROVED`
 * directly is refused by `ForbidsAgentApproval`, and rightly: the one way a row becomes approved names who
 * decided it.
 */
function approveRegisteredRules(): void
{
    $approver = settingsAdminUser();

    foreach (TaxRuleVersion::query()->withoutGlobalScopes()->get() as $version) {
        $version->approveByPerson($approver->id);
    }
}

/**
 * A BNR yearly archive from the package's own fixtures.
 *
 * Shared because two files drive the same archive now — the action's test and the command's, which exercises
 * the loop around it.
 */
function yearlyFixture(string $name): string
{
    $contents = file_get_contents(fixtureFromPackage(FxRate::class, 'Fx/Bnr/' . $name));

    if ($contents === false) {
        throw new RuntimeException("Missing test fixture: {$name}");
    }

    return $contents;
}

/**
 * A loopback port nothing is listening on, so a connection to it is refused at once rather than waiting
 * out a timeout. Asked of the kernel rather than hard-coded: a port that happens to be in use on the
 * machine running the suite would make a "nothing answers" test pass for the wrong reason.
 */
function portNobodyIsListeningOn(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');

    if ($server === false) {
        throw new RuntimeException('Could not open a socket to find a free port.');
    }

    $name = (string) stream_socket_get_name($server, false);
    fclose($server);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

/** What a stub clamd says to every connection: the reply to `zPING`. */
const CLAMD_PONG = 'fwrite($c, "PONG\0");';

/** What a stub SMTP server says: a greeting, `250` to everything, `221` to `QUIT`. */
const SMTP_GREETING = 'fwrite($c, "220 stub ESMTP\r\n"); while (($l = fgets($c)) !== false) { if (strtoupper(substr($l, 0, 4)) === "QUIT") { fwrite($c, "221 bye\r\n"); break; } fwrite($c, "250 stub\r\n"); }';

/**
 * A TCP server in a child process, running `$perConnection` (PHP, with the connection as `$c`) for every
 * connection, on a free loopback port.
 *
 * A child rather than a socket in this process: the probe under test blocks reading the reply, so
 * nothing in this process could be there to send it. It answers every connection rather than one, so
 * the readiness check here does not use up the one the test is about to make. Skips the test when no
 * child can be started — the healthy case is then unreachable, not failed.
 *
 * @return array{0: int, 1: resource} the port, and the process for {@see stopStubServer()}
 */
function startStubServer(string $perConnection): array
{
    $port = portNobodyIsListeningOn();

    $process = proc_open(
        [PHP_BINARY, '-r', '$s = stream_socket_server("tcp://127.0.0.1:' . $port . '"); while ($c = stream_socket_accept($s, 30)) { ' . $perConnection . ' fclose($c); }'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
    );

    if (! is_resource($process)) {
        PHPUnit\Framework\Assert::markTestSkipped('No stub server could be started.');
    }

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $readiness = @fsockopen('127.0.0.1', $port, $code, $message, 0.2);

        if ($readiness !== false) {
            fclose($readiness);

            return [$port, $process];
        }

        usleep(100_000);
    }

    stopStubServer($process);
    PHPUnit\Framework\Assert::markTestSkipped('The stub server never started listening.');
}

/** @param resource $process */
function stopStubServer($process): void
{
    proc_terminate($process);
    proc_close($process);
}

/**
 * Reads a queued portal search page by page, as the queue would, with the queue faked
 * (`Queue::fake()`): each `ReadPortalSearchPage` pushed is handled in turn, the ones it pushes after it
 * included, and one that throws is given what the queue gives a job whose retries are spent — its
 * `failed()`. So a test sees a search end exactly as it would on a worker, without a worker.
 */
function readPortalSearchPages(): void
{
    for ($ran = 0; ($job = portalSearchPagesPushed()->get($ran)) !== null; $ran++) {
        try {
            $job->handle(app(PortalSearch::class));
        } catch (Throwable $exception) {
            $job->failed($exception);
        }
    }
}

/**
 * Every `ReadPortalSearchPage` pushed so far, in order, from the faked queue.
 *
 * @return Collection<int, ReadPortalSearchPage>
 */
function portalSearchPagesPushed(): Collection
{
    $queue = Queue::getFacadeRoot();

    if (! $queue instanceof QueueFake) {
        throw new LogicException('A portal search is read from a faked queue: call Queue::fake() first.');
    }

    return $queue->pushed(ReadPortalSearchPage::class)
        ->filter(static fn (mixed $job): bool => $job instanceof ReadPortalSearchPage)
        ->values();
}

/**
 * The id of the portal search a `POST /api/v1/legislation/portal-searches` started.
 *
 * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $started
 */
function portalSearchId(TestResponse $started): string
{
    $id = $started->assertStatus(202)->json('id');

    return is_string($id) ? $id : throw new LogicException('The portal search started without an id.');
}
