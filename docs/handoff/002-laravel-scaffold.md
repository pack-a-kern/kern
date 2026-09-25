# 002 — Laravel 13 application scaffold

**Milestone:** M0   **Status:** complete
**Date:** 2026-09-08

## What was built

A Laravel 13 application at `apps/api/` with the quality tooling, frontend stack, and baseline
runtime conventions the blueprint requires, and nothing else. No domain modules, no business
migrations, no auth flows, no encryption — that is Milestone 1 (`docs/handoff/00X-security-foundation.md`,
not yet written).

Entry points:
- Backend: `GET /` renders the Inertia `home` page (`routes/web.php` → `resources/js/pages/home.tsx`).
- Health check: `GET /up` (Laravel's built-in health route, wired via `bootstrap/app.php`'s
  `health: '/up'`) — this is what the Docker healthcheck should call.
- Frontend build: `npm run build` → `public/build/` (Vite manifest), dev server via `npm run dev`.

## Files created / changed

Backend
- `composer.json` — project renamed `kern/api`; tight `^` constraints on verified minors per
  `docs/DEPENDENCY-POLICY.md` (never `*`, never unbounded `>=`); `config.platform.php` pinned to
  `8.5.10` and `config.platform-check: false` (see **Key decisions**).
- `pint.json` — PSR-12 preset + `declare_strict_types`, `strict_param`, `strict_comparison`, import
  ordering, single quotes.
- `phpstan.neon` — Larastan + Pest PHPStan plugin, **level 9**, analysing `app`, `bootstrap/app.php`,
  `bootstrap/providers.php`, `config`, `database`, `routes`, `tests`. No baseline file.
- `deptrac.php` — PHP-format Deptrac config (Deptrac 4.x's native format; YAML still works but this
  is what `deptrac init` generates and what the installed 4.7.1 actually documents in its own
  vendored template). Declares layers `Support`, `Identity`, `Subjects`, `Audit` matching
  `app/Support/*` and `packages/tax-romania/src/{Identity,Subjects,Audit}/*`. Ruleset: each domain layer may
  depend on `Support`; domain layers may not depend on each other. **No layer contains any file yet**
  — `packages/tax-romania/src/` does not exist (§19.1/AGENTS.md §6 forbid empty scaffolding). The M1 agent adding
  `packages/platform/src/Identity/...` gets boundary enforcement for free; adding a *new* module (Wallet,
  Documents, …) requires adding its layer to `deptrac.php` in the same change, or Deptrac won't see it.
- `app/Http/Middleware/HandleInertiaRequests.php` — Inertia root-view middleware, registered in
  `bootstrap/app.php`'s `web` group.
- `app/Http/Controllers/Controller.php`, `app/Providers/AppServiceProvider.php`,
  `bootstrap/app.php`, `bootstrap/providers.php`, `routes/console.php` — `declare(strict_types=1)`
  added (framework generated these without it).
- `routes/web.php` — single `home` route, Inertia render.
- `resources/views/app.blade.php` — Inertia root Blade template (`welcome.blade.php` deleted).
- `config/broadcasting.php`, `config/reverb.php`, `config/horizon.php` — hand-written (not
  `artisan vendor:publish`-generated, since `artisan` cannot run on this host — see **How to
  verify**); content matches the installed packages' own vendored defaults, trimmed of Pusher/Ably
  (blueprint §3.2: Reverb is the only broadcaster) and commented-out example lines.
- `config/database.php` — trimmed to `pgsql` + `sqlite` (test-only); removed `mysql`/`mariadb`/`sqlsrv`
  (blueprint §1.1: PostgreSQL only).
- `config/cache.php`, `config/queue.php` — trimmed to `redis` + `array`/`sync` (test-only); removed
  `database`/`file`/`memcached`/`dynamodb`/`beanstalkd`/`sqs`/`failover`/etc. Queue's `failed` driver
  is `database-uuids` against Postgres (durable audit trail, not Redis).
- `config/session.php` — driver fallback changed `database` → `redis`.
- `config/filesystems.php` — `public` disk and `links` removed (this project never serves a public
  bucket — everything goes through the encrypted object-storage service in M1+); default disk is
  `s3` (MinIO-compatible, already supports `endpoint`/`use_path_style_endpoint`).
- `config/mail.php` — trimmed to `smtp` (Mailpit, dev-only per §3.1) + `log` + `array`; removed
  ses/postmark/resend/sendmail/failover/roundrobin (unused, no credentials anywhere).
- `config/services.php` — emptied (postmark/resend/ses/slack credentials for mailers we don't use).
- `config/logging.php` — removed unused `slack` and `papertrail` channels.
- `config/auth.php` **deleted**. Nothing in M0 authenticates a user (no login route, no `auth`
  middleware). The stock file pointed at `App\Models\User`, which is also deleted (see next point).
  M1 designs Identity from `BLUEPRINT.md` §5.1 (ULID PK, `email_ciphertext`, blind index — nothing like
  Laravel's stock `users` table), so keeping the stock auth scaffolding around would be a false start,
  not a head start.
- `app/Models/User.php`, `database/factories/UserFactory.php` **deleted**;
  `database/migrations/0001_01_01_000000_create_users_table.php` **deleted** (created `users`,
  `password_reset_tokens`, `sessions` — all three unused: no Identity model, no password-reset flow,
  and sessions live in Redis per `.env.example`).
- `database/migrations/0001_01_01_000001_create_cache_table.php` **deleted** (cache is Redis-only).
- `database/migrations/0001_01_01_000002_create_jobs_table.php` → renamed
  `0001_01_01_000001_create_failed_jobs_table.php`, trimmed to only the `failed_jobs` table (`jobs`
  and `job_batches` dropped — queue is Redis-only via Horizon, and nothing calls `Bus::batch()` yet).
- `database/seeders/DatabaseSeeder.php` — emptied (no `User::factory()` call left to make).
- `.env.example` / `.env` — full rewrite: Postgres default connection, Redis for
  cache/queue/session/broadcasting, Reverb env vars, S3/MinIO env vars, `APP_LOCALE=ro`. `.env` has
  a locally-generated `APP_KEY` and random `REVERB_APP_*` values (gitignored, dev-only, not secrets
  worth rotating).
- `phpunit.xml` — dropped the `Unit` testsuite (no unit tests exist — no domain logic yet) and three
  env vars for packages that aren't installed (Pulse/Telescope/Nightwatch).
- `tests/Pest.php`, `tests/TestCase.php`, `tests/Feature/HealthEndpointTest.php`,
  `tests/Feature/HomePageTest.php` — Pest replaces PHPUnit-style tests (`tests/Unit/` removed, empty).

Frontend
- `package.json` — **exact** versions everywhere (no `^`/`~`), per `docs/DEPENDENCY-POLICY.md`.
  `react`, `react-dom`, `@inertiajs/react`, plus the dev toolchain (see **Dependencies** below).
- `vite.config.js` — added `@vitejs/plugin-react`; entry is `resources/js/app.tsx`; dropped the
  skeleton's Bunny-fonts plugin (network dependency at build time for a font nothing uses yet).
- `tsconfig.json` — strict mode, plus `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`,
  `verbatimModuleSyntax`.
- `eslint.config.js` — flat config: `typescript-eslint` strict-type-checked, `react-hooks`,
  `react-refresh`, `eslint-config-prettier` last.
- `.prettierrc.json` — 4-space indent (matches PHP/`.editorconfig`), single quotes.
- `resources/js/app.tsx` — Inertia `createInertiaApp` entry (glob-based page resolution from
  `resources/js/pages/**/*.tsx`).
- `resources/js/pages/home.tsx` — the one page that exists.
- `resources/js/vite-env.d.ts` — Vite client types + `ImportMetaEnv` shape.
- `resources/css/app.css` — Tailwind v4 import; dropped the custom-font `@theme` block (no font
  loaded, see vite.config.js note above).

## Key decisions

1. **Host PHP is 8.3; the target runtime is 8.5, and the real minimum turned out to be 8.4, not
   8.3.** `laravel/framework` itself still declares `"php": "^8.3"`, but the Symfony 8.1 it actually
   resolves to (via its own loose `symfony/*` constraints) ships classes using PHP 8.4 property
   hooks (e.g. `vendor/symfony/http-foundation/Request.php`) — a **hard parse error** on PHP 8.3, not
   a soft compatibility warning. `pestphp/pest` v5 independently requires `^8.4`. So `composer.json`
   declares `"php": "^8.4"` — this is the honest minimum, not a preference.
   To let Composer *resolve* the dependency graph on this host anyway, `composer.json` sets
   `config.platform.php: 8.5.10` (resolve as if running the target runtime) **and**
   `config.platform-check: false` (skip the generated runtime guard that would otherwise fatal on
   every `php artisan` invocation on this host). Neither setting affects the container, which really
   does run 8.5.10.
2. **Verified-versions.md should gain one line**: Horizon needs `ext-pcntl` in addition to the four
   extensions it already lists (`pdo_pgsql`, `redis`, `bcmath`, `intl`) for `php:8.5-fpm-trixie`.
   Discovered by `composer install` refusing to proceed without it. I did not edit
   `docs/reference/verified-versions.md` myself — it's outside this task's stated scope
   (`apps/api/**` + this handoff) — but whoever builds the Dockerfile needs
   `docker-php-ext-install pdo_pgsql bcmath intl pcntl` plus `pecl install redis && docker-php-ext-enable redis`.
3. **Pint runs on host PHP 8.3; PHPStan/Pest/Deptrac do not.** Pint (nikic/php-parser-based) parses
   PHP 8.4/8.5 syntax fine even executing under 8.3. PHPStan, Pest and Deptrac all transitively
   autoload `phpunit/phpunit`, which itself now uses property hooks — same hard parse error. I could
   not run these three via the host's `php` binary. I built a throwaway verification image
   (`php:8.5-cli-trixie` + `pdo_pgsql`/`bcmath`/`intl`/`pcntl`/`redis` + Composer) and ran everything
   through it — see **How to verify** for the exact real output. The image and its Dockerfile were
   deleted afterward; they were verification scaffolding, not a deliverable, and are not the
   production Dockerfile (that's the Docker agent's job, `/docker`).
4. **PHPStan level 9, no baseline, zero suppressions.** One real bug found and fixed:
   `config/horizon.php`'s `Str::slug(env('APP_NAME', ...), '_')` — `env()` returns `mixed`, needed an
   explicit `(string)` cast (the exact pattern Laravel's own `cache.php`/`session.php` already use).
5. **`pestphp/pest-plugin-phpstan` added** (dev, MIT, released 2026-09-06, requires
   `phpstan ^2.2.5` — satisfied). Without it, PHPStan level 9 correctly flags every
   `test(...)`/`it(...)` closure's `$this->get(...)` as "undefined method" — Pest rebinds `$this` to
   `Tests\TestCase` at runtime via reflection, which static analysis can't see without this official
   plugin. This is the documented, maintained fix, not a suppression.
6. **TypeScript pinned to 6.0.3, not the npm `latest` (7.0.2).** `typescript-eslint@8.70.0`'s peer
   dependency is `typescript: '>=4.8.4 <6.1.0'` — TypeScript 7 breaks it. 6.0.3 is the newest release
   inside that window (released 2026-04-16, still MIT, still receiving patches). Re-check this pin
   whenever `typescript-eslint` bumps its peer range.
7. **Horizon dashboard authorization is deliberately left at the framework default** (`local`
   environment only; denied everywhere else — see `Laravel\Horizon\Horizon::check()`), not overridden
   with a published `App\Providers\HorizonServiceProvider`. Writing a real `viewHorizon` gate requires
   an actual user/role model, which is Identity (M1). The framework default already fails closed in
   non-local environments, so this is safe, not merely deferred. **M1 must publish this provider
   before Horizon is exposed anywhere but local.**
8. **Inertia SSR is not wired**, and that's fine as shipped: `inertia.ssr.enabled` defaults `true` in
   the vendored config (never published — no reason to override defaults we're not changing), but
   `Inertia\Ssr\HttpGateway::dispatch()` checks `ensure_bundle_exists` and finds no
   `bootstrap/ssr/ssr.mjs`, so it returns `null` before ever attempting a network call. Verified by
   reading `vendor/inertiajs/inertia-laravel/src/Ssr/HttpGateway.php` — no per-request latency, no
   silent failure path. Confirmed empirically too: `tests/Feature/HomePageTest.php` passes.
9. **Reverb is configured but has no channels yet.** `bootstrap/app.php`'s `withRouting()` does not
   pass a `channels:` path, and `routes/channels.php` does not exist, because there is nothing to
   authorize yet (no domain events — those start at M7 per blueprint §16). Adding the first private
   channel means creating `routes/channels.php` and passing `channels: __DIR__.'/../routes/channels.php'`
   to `withRouting()` in the same change.
10. **`laravel/pao` and `laravel/agent-detector`/`laravel/sentinel`** appeared as transitive/default
    dependencies of a fresh `laravel/laravel:^13.0` + `laravel/horizon` install, not something I added
    deliberately — `laravel/pao` ships with new Laravel skeletons (agent-optimized test/tool output);
    `laravel/sentinel` is pulled in by Horizon. Left as-is; not worth fighting the framework's own
    default dependency graph for a package that's already MIT/first-party/maintained.

## How to verify

All commands run from `apps/api/`. Everything requiring real code execution was run inside a
throwaway `php:8.5-cli-trixie`-based container (see decision #3) since the host CLI is PHP 8.3.32.

```bash
composer validate --strict
# ./composer.json is valid

composer audit
# No security vulnerability advisories found.

vendor/bin/pint --test
# PASS .......................................................... 27 files

vendor/bin/phpstan analyse --memory-limit=512M     # level 9, see phpstan.neon
# [OK] No errors

vendor/bin/pest
# PASS  Tests\Feature\HealthEndpointTest
#   ✓ the health endpoint reports the application is up
# PASS  Tests\Feature\HomePageTest
#   ✓ the home page renders the home Inertia component
# Tests: 2 passed (9 assertions)

vendor/bin/deptrac analyse --config-file=deptrac.php
# Violations 0, Skipped 0, Uncovered 0, Warnings 0, Errors 0

npm ci
npx tsc --noEmit        # no output = no errors
npx eslint resources/js # no output = no errors
npx prettier --check resources/js
# All matched files use Prettier code style!

npm run build
# ✓ 567 modules transformed, built in ~280ms

npm audit --audit-level=high
# found 0 vulnerabilities
```

Additionally verified against **real** Postgres and Redis (throwaway `pgvector/pgvector:pg18-trixie`
and `redis:8-trixie` containers, matching `docs/reference/verified-versions.md`'s pinned tags, torn
down afterward — not part of this deliverable):

```bash
php artisan migrate --force
# Creating migration table ... DONE
# 0001_01_01_000001_create_failed_jobs_table ... DONE

# \d failed_jobs in psql shows exactly the expected columns/indexes.

php artisan tinker --execute="cache()->put('probe','ok',10); echo cache()->get('probe');"
# ok

php artisan route:list
# GET|HEAD  /  ................ home
# GET|HEAD  up ................ (health check)
# + Horizon dashboard/API routes, + Inertia devtools routes, + storage.local (signed local-disk serving)

php artisan config:cache && php artisan config:clear
# Configuration cached successfully. / Configuration cache cleared successfully.
```

**Not run**: `composer audit`'s Python/Docker-build/SBOM/container-scan equivalents from §22 —
out of scope for this task (Python service and Docker images belong to other agents in this wave).

## Dependencies added (justification, license, last release)

Composer (`composer.lock`, direct deps only — see `composer.json` for exact constraints):

| Package | Version | License | Last release | Why |
|---|---|---|---|---|
| `laravel/framework` | 13.30.1 | MIT | 2026-09-01 | The framework (blueprint §1.1). |
| `inertiajs/inertia-laravel` | 3.3.3 | MIT | 2026-09-04 | Blueprint §1.3: Laravel + Inertia. |
| `laravel/reverb` | 1.11.1 | MIT | 2026-08-06 | Blueprint §1.1/§3.2: WebSocket broadcasting. |
| `laravel/horizon` | 5.48.3 | MIT | 2026-08-10 | Blueprint §1.1: queue dashboard/supervisor over Redis. |
| `league/flysystem-aws-s3-v3` | 3.35.3 | MIT | 2026-08-08 | S3/MinIO-compatible object storage driver (§1.1). |
| `laravel/tinker` | 3.0.2 | MIT | 2026-03-17 | Stock Laravel skeleton dependency; used for the DB/Redis smoke tests above. |
| `laravel/pint` (dev) | 1.31.0 | MIT | 2026-09-07 | PSR-12 formatter, required by §19.3/§22. |
| `larastan/larastan` (dev) | 3.11.0 | MIT | 2026-09-01 | Static analysis, required by §22, level 9. |
| `deptrac/deptrac` (dev) | 4.7.1 | MIT | 2026-07-23 | Module-boundary enforcement, §2.1/§22. Not `qossmic/deptrac-shim` (dead). |
| `pestphp/pest` (dev) | 5.1.4 | MIT | 2026-09-07 | Test runner, §21/§22. |
| `pestphp/pest-plugin-laravel` (dev) | 5.0.1 | MIT | 2026-07-29 | Pest's Laravel HTTP-testing helpers (`assertInertia`, etc.). |
| `pestphp/pest-plugin-phpstan` (dev) | 5.2.1 | MIT | 2026-09-06 | Makes PHPStan understand Pest's `$this` rebinding — see decision #5. |
| `fakerphp/faker` (dev) | 1.24.1 | MIT | 2024-11-21 | Stock Laravel skeleton dependency (factories, not yet used). |
| `mockery/mockery` (dev) | 1.6.15 | BSD-3-Clause | 2026-08-19 | Stock Laravel skeleton dependency. |
| `nunomaduro/collision` (dev) | 8.9.5 | MIT | 2026-07-15 | Stock Laravel skeleton dependency (CLI error rendering). |
| `laravel/pail` (dev), `laravel/pao` (dev) | 1.2.7 / 1.1.5 | MIT | 2026-05 / 2026-09 | Stock Laravel skeleton dependencies (log tailing, agent-friendly test output). |

npm (`package.json`, all pinned exact, `package-lock.json` committed):

| Package | Version | License | Last release | Why |
|---|---|---|---|---|
| `react`, `react-dom` | 19.2.8 | MIT | 2026-09-07 | Blueprint §1.3. |
| `@inertiajs/react` | 3.7.0 | MIT | 2026-08-18 | Inertia's React adapter. |
| `vite` | 8.2.2 | MIT | 2026-09-07 | Blueprint §1.3. |
| `laravel-vite-plugin` | 3.2.0 | MIT | 2026-08-11 | Laravel↔Vite integration (stock skeleton dependency). |
| `@vitejs/plugin-react` | 6.1.1 | MIT | 2026-08-28 | React Fast Refresh for Vite. |
| `tailwindcss`, `@tailwindcss/vite` | 4.3.3 | MIT | 2026-08-31 | Blueprint §1.3 (stock skeleton dependency). |
| `typescript` | 6.0.3 | Apache-2.0 | 2026-04-16 | Pinned below `latest` (7.0.2) for `typescript-eslint` peer compat — see decision #6. |
| `typescript-eslint` | 8.70.0 | MIT | 2026-09-07 | Type-aware ESLint rules. |
| `eslint` | 10.10.0 | MIT | 2026-09-04 | §19.5/§22. |
| `eslint-plugin-react-hooks` | 7.1.1 | MIT | 2026-09-07 | Rules-of-hooks enforcement. |
| `eslint-plugin-react-refresh` | 0.5.6 | MIT | 2026-09-02 | Fast-refresh-safety lint rule. |
| `eslint-config-prettier` | 10.1.8 | MIT | 2025-07-20 | Disables ESLint formatting rules that fight Prettier. |
| `@eslint/js`, `globals` | 10.0.1 / 17.12.0 | MIT | 2026-07 / 2026-09 | ESLint flat-config base + browser globals. |
| `prettier` | 3.9.6 | MIT | 2026-07-21 | §19.5/§22 formatting. |
| `@types/react`, `@types/react-dom` | 19.2.18 / 19.2.7 | MIT | 2026-07 / 2026-09 | Type definitions. |

`npm audit --audit-level=high` and `composer audit`: **0 vulnerabilities**, both re-checked after
every dependency addition in this task.

## Interfaces exposed to other modules

- `GET /up` — health check (Laravel core `health:` route registered in `bootstrap/app.php`). Point
  the Docker healthcheck at this.
- `app/Http/Middleware/HandleInertiaRequests.php` — extend its `share()` method to add global
  Inertia props (e.g. authenticated user) once Identity exists. It's registered in `bootstrap/app.php`
  and does not need re-registering.
- `resources/js/pages/<name>.tsx` — convention for every future page (`Inertia::render('module/page')`
  → `resources/js/pages/module/page.tsx`), matching `inertia.pages.paths` default
  (`resource_path('js/pages')`), which was never overridden.
- `deptrac.php` — add a `Layer::withName('<Module>')` + `DirectoryConfig::create('packages/tax-romania/src/<Module>/.*')`
  and a `Ruleset::forLayer(...)` entry in the same commit that adds the module's first class.
  Undeclared modules are invisible to the boundary check, not automatically forbidden.
- `phpstan.neon` — add new top-level directories to `parameters.paths` as they're created (e.g.
  `packages/tax-romania/src` once it exists) so they're actually analysed.
- Redis connections `default` and `cache` (`config/database.php`), queue connection `redis`
  (`config/queue.php`), cache store `redis` (`config/cache.php`), session driver `redis` — all wired
  and env-driven, ready for `docker-compose`'s `redis` service.
- `s3` disk (`config/filesystems.php`) — env-driven, ready for MinIO (`AWS_ENDPOINT`,
  `AWS_USE_PATH_STYLE_ENDPOINT=true`).

## What is NOT done

- No `packages/tax-romania/src/*` modules — Identity, Subjects, Wallet, Ledger, Documents, TaxKnowledge, TaxEngine,
  Declarations, Spv, Notifications, Audit are all Milestone 1+ (per this task's explicit scope).
- No auth, no users table, no passkeys/MFA, no envelope encryption, no blind indexes — Milestone 1.
- No `routes/channels.php`, no private/presence Reverb channels — nothing to authorize yet.
- No `HorizonServiceProvider`/`viewHorizon` gate beyond the framework's local-only default — needs a
  real user/role model (Milestone 1) before Horizon is exposed outside `local`.
- No SSR bundle for Inertia (not needed yet; confirmed safe no-op, see decision #8).
- Composer's `platform-check: false` and `platform.php` pin are a **host workaround**, documented
  above. They must not be read as "this app runs on PHP 8.3" — it does not, and cannot (see decision #1).
- `docs/reference/verified-versions.md` needs the `ext-pcntl` addition noted in decision #2; I did
  not make that edit myself (outside this task's stated file scope).
- Did not touch `/docker`, `compose.yaml`, the Python service, or any ADRs — explicitly out of scope
  and other agents' concurrent work per the coordinator.

## Open questions

- Should `docs/reference/verified-versions.md` be updated with the `ext-pcntl` requirement now, or
  is that the Docker agent's job when they hit the same wall building the Dockerfile? Flagging rather
  than guessing, since that file is outside this task's stated scope.
- `config/filesystems.php`'s `local` disk still has `serve => true` (Laravel 13's built-in signed-URL
  local-file serving, `storage.local` route, visible in `route:list` above) — stock default, untouched.
  Confirm in Milestone 1/7 whether this should be disabled once the real encrypted-object-storage
  service exists, so there are two ways to serve a file instead of one.

## Sources consulted

- `BLUEPRINT.md` §1.1, §1.3, §2.1, §3.2, §19, §22, §23, §29 (blueprint).
- `docs/AGENTS.md` (binding rules), `docs/DEPENDENCY-POLICY.md`, `docs/CODE-QUALITY.md`.
- `docs/reference/verified-versions.md` (package/runtime versions used as given, not re-derived).
- Installed package source read directly for behavior claims made above (not assumed):
  `vendor/laravel/horizon/src/Horizon.php`, `vendor/laravel/horizon/src/HorizonApplicationServiceProvider.php`,
  `vendor/inertiajs/inertia-laravel/src/Ssr/HttpGateway.php`,
  `vendor/deptrac/deptrac/config/deptrac_template.php` and `vendor/deptrac/deptrac/src/Contract/Config/*`.
- npm registry (`npm view <pkg> version/license/time.modified/peerDependencies/dist-tags`) and
  Packagist (`repo.packagist.org/p2/<pkg>.json`) queried live for every dependency version, license,
  and release date cited above — not from training-data memory.
