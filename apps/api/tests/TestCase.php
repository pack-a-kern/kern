<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Reset throttle state before every test.
     *
     * Every test hits the API from 127.0.0.1, so the login/MFA/registration throttles
     * (`IdentityServiceProvider::boot()`) accumulate across the suite: an Identity test passes
     * alone and returns 429 when run after its neighbours. That is a test-isolation defect, not a
     * rate-limiting defect — the throttle is doing its job correctly.
     *
     * Done here rather than in a Pest `beforeEach` because this runs *before* a test file's own
     * setup. A global `beforeEach` runs after it, and would destroy state the test just prepared —
     * it wiped the WebAuthn registration challenge and broke the passkey tests.
     *
     * `RateLimiter::clear()` is not usable here: it takes a resolved cache key, while
     * `ThrottleRequests` derives keys from the limiter name plus a per-request signature, so the
     * keys cannot be enumerated from the limiter names alone.
     */
    /**
     * Databases this suite is allowed to touch.
     *
     * Anything that runs `RefreshDatabase` runs `migrate:fresh`, which drops every table. Some
     * suites legitimately need a real Postgres (`SELECT … FOR UPDATE`, `ilike`, the audit chain),
     * and the only Postgres database this project defines is the *development* one — so a run
     * that reaches it does not fail, it silently destroys the developer's data. That happened:
     * a single `pest` invocation without the sqlite flags wiped users, settings and an ingested
     * legislation corpus.
     *
     * `phpunit.xml` forces sqlite, but a shell-level `DB_CONNECTION` can still win depending on
     * how the process is launched, which is exactly the case that did the damage. This check does
     * not depend on that resolution order: it asks the connection, after boot, what it is actually
     * pointed at, and refuses anything not on this list.
     */
    private const array ALLOWED_DATABASES = [':memory:', 'taxos_test'];

    /**
     * The guard has to run here, not in `setUp()`.
     *
     * `RefreshDatabase` does its `migrate:fresh` from `setUpTraits()`, which `parent::setUp()`
     * calls — so a check placed after `parent::setUp()` runs *after* every table has already been
     * dropped. It reports the problem having already caused it, which is worse than no check at
     * all because it reads like protection. This override is the first point at which the
     * application is booted (so the connection can be asked what it is pointed at) and no trait
     * setup has run yet.
     */
    /**
     * @return array<class-string, class-string>
     */
    protected function setUpTraits(): array
    {
        $this->refuseNonTestDatabase();

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * Aborts the run before any migration touches a database that is not a test database.
     *
     * Deliberately a hard failure with no opt-out flag: an environment variable that disables this
     * would be set once, in a hurry, and then forgotten — which is the same failure mode it exists
     * to prevent. To run the Postgres-only suites, create the test database and point at it:
     * `DB_CONNECTION=pgsql DB_DATABASE=taxos_test vendor/bin/pest`.
     */
    private function refuseNonTestDatabase(): void
    {
        $database = DB::connection()->getDatabaseName();

        if (in_array($database, self::ALLOWED_DATABASES, true)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run tests against database [%s]: the suite runs migrate:fresh and would '
            . 'drop every table. Allowed: %s. For the Postgres-only suites use '
            . 'DB_CONNECTION=pgsql DB_DATABASE=taxos_test.',
            $database,
            implode(', ', self::ALLOWED_DATABASES),
        ));
    }
}
