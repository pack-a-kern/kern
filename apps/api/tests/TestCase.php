<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Kern\Documents\Scanning\ScanOutcome;
use Kern\Documents\Scanning\VirusScanner;
use RuntimeException;
use Tests\Fakes\FakeVirusScanner;

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
    private const array ALLOWED_DATABASES = [':memory:', 'kern_test'];

    /**
     * Whether the connection is pointed at a database the suite may destroy.
     *
     * Public because the guard below is not the only thing that writes destructively: `Pest.php`
     * truncates the identity and audit tables after the Audit suite, and a failing test does not
     * stop it — PHPUnit skips `tearDown()` when `setUp()` throws, but a Pest `afterEach` is not
     * `tearDown()`, so it still ran on a database this class had just refused. The account rows in
     * a development database were emptied exactly that way. Both places ask this one question now,
     * so neither can be safe while the other is not.
     */
    public static function isTestDatabase(): bool
    {
        return in_array(DB::connection()->getDatabaseName(), self::ALLOWED_DATABASES, true);
    }

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

        /*
         * Object storage is a local fake in every test, never the real store.
         *
         * Nothing pointed it anywhere: `phpunit.xml` configures no filesystem, so a test that wrote a
         * document wrote it to whatever `apps/api/.env` names — the development MinIO, and then the
         * storage server itself. It left 10,098 objects and 5,869 abandoned subject prefixes behind
         * before anybody noticed, in the same bucket the running application uses. A suite that has to
         * be *pointed away* from production storage is a suite that writes to it the first time
         * somebody forgets.
         *
         * Both disks, because they are deliberately separate stores: a document is written to one and
         * an unscanned upload to the other, and faking only the first would quietly keep writing the
         * second to a real bucket.
         *
         * `Storage::fake()` also empties the fake between tests, so no test inherits another's files.
         * A test that wants the real thing says so — see `EncryptedObjectStoreLiveMinioTest`, which
         * rebuilds the `s3` disk from `OBJECTSTORE_TEST_*` and forgets this fake to get it.
         */
        Storage::fake('s3');
        Storage::fake('quarantine');

        /*
         * The same reasoning, for the two other things a development machine has and CI does not.
         *
         * The virus scanner: a test that uploaded a file reached the development ClamAV and passed
         * only because one was running. Every file is clean here; a test about the scanner binds
         * its own outcome, and the live tests (`ClamdVirusScannerLiveTest`) build a real client.
         *
         * The Vite build: a page rendered in a test read `public/build/manifest.json`, which exists
         * only where someone ran `npm run build`. What a test checks is the page, not the assets.
         */
        $this->app->instance(VirusScanner::class, FakeVirusScanner::returning(ScanOutcome::CLEAN));
        $this->withoutVite();
    }

    /**
     * Aborts the run before any migration touches a database that is not a test database.
     *
     * Deliberately a hard failure with no opt-out flag: an environment variable that disables this
     * would be set once, in a hurry, and then forgotten — which is the same failure mode it exists
     * to prevent. To run the Postgres-only suites, create the test database and point at it:
     * `DB_CONNECTION=pgsql DB_DATABASE=kern_test vendor/bin/pest`.
     */
    private function refuseNonTestDatabase(): void
    {
        $database = DB::connection()->getDatabaseName();

        if (self::isTestDatabase()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run tests against database [%s]: the suite runs migrate:fresh and would '
            . 'drop every table. Allowed: %s. For the Postgres-only suites use '
            . 'DB_CONNECTION=pgsql DB_DATABASE=kern_test.',
            $database,
            implode(', ', self::ALLOWED_DATABASES),
        ));
    }
}
