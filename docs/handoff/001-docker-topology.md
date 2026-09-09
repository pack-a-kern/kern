# 001 — Docker container topology

**Milestone:** M0   **Status:** partial
**Date:** 2026-09-08

## What was built

The full container topology for TaxOS România per `BLUEPRINT.md` §3: 13 always-on services
(`edge`, `app`, `queue`, `scheduler`, `reverb`, `python-api`, `python-worker`, `spv-browser`,
`clamav`, `postgres`, `redis`, `minio`, `openbao`) plus `mailpit` (dev-only, in the override file)
and two one-shot init helpers (`minio-init-perms`, `openbao-init`), wired across five Docker
networks with the segmentation, capability-dropping, read-only rootfs, and healthchecks
`BLUEPRINT.md` §3.3/§3.4 ask for.

**Important context change mid-task:** `apps/api` (Laravel) and `apps/processing` (FastAPI) landed
from parallel agents while this work was in progress. The original brief assumed neither existed
yet ("reference those paths as if they exist; do not write into them"). Once they appeared, every
Dockerfile in this handoff was built and boot-tested against the *real* code — not just written to
compile in the abstract — which surfaced several real bugs (missing `ext-pcntl`, missing runtime
shared libraries, a broken autoloader step, a wrong Postgres 18 volume path, missing Linux
capabilities for Postgres/Redis's own entrypoints) that a topology built against empty directories
would never have caught. `apps/spv-browser` is still empty, so `docker/playwright/Dockerfile` is
unverified beyond the base image and account it depends on.

Entry points:
- `compose.yaml` — production-shape topology (no host ports on the data layer, `app_net`/
  `data_net` are `internal: true`, every image pinned to an exact tag).
- `compose.override.yaml` — dev-only additions (mailpit, published DB/object-storage ports via a
  second non-internal network, bind-mounted source for the four Laravel processes and the two
  Python processes). Compose loads both automatically when run from the repo root.
- `Makefile` — `up`, `down`, `build`, `logs`, `shell`, `test`, `lint`, `fresh`. `test`/`lint` build
  a `test` Dockerfile stage (dev dependencies) and run Pest/Pint/PHPStan and pytest/ruff/pyright —
  all four verified to actually pass against the real `apps/api`/`apps/processing` code.

## Files created / changed

- `compose.yaml` — the 15-service production-shape topology, five networks, six named volumes.
- `compose.override.yaml` — dev conveniences layered on top.
- `.env.example` — every variable the compose files interpolate, with a comment on where each is
  consumed.
- `.dockerignore` — keeps host `vendor/`, `node_modules/`, `.venv/`, `.env`, and cache directories
  out of build contexts.
- `.gitignore` — root-level ignores (`.env`, editor noise).
- `Makefile` — the everyday commands.
- `docker/php/Dockerfile` — `app`/`queue`/`scheduler`/`reverb` image (four stages:
  `ext-builder`, `composer-bin`, `vendor`, `runtime`, plus a `test` stage with dev dependencies).
- `docker/php/php.ini`, `docker/php/www.conf` — opcache/upload/logging config; fpm pool with
  `pm.status_path=/fpm-status` for the healthcheck.
- `docker/nginx/Dockerfile`, `docker/nginx/nginx.conf`, `docker/nginx/conf.d/app.conf` — `edge`
  image; self-contained `/healthz`, request-time DNS resolution of `app` via Docker's embedded
  resolver.
- `docker/python/Dockerfile` — shared `python-api`/`python-worker` image (`base`, `deps`,
  `runtime`, `test` stages).
- `docker/playwright/Dockerfile` — `spv-browser` image, built on Microsoft's official Playwright
  image. Cannot be built yet — `apps/spv-browser` is empty.
- `docker/openbao/init.sh` — idempotent transit-engine + KEK bootstrap, POSIX `/bin/sh`.
- `docker/postgres/init/001-extensions.sql` — `vector`/`unaccent`/`pg_trgm`.

## Key decisions

1. **`app`/`queue`/`scheduler`/`reverb` also attach to `data_net`**, beyond the literal service
   list in the brief's `data_net` bullet. All four run Laravel and need a direct Postgres
   connection to boot at all — the brief's network list only named `data_net`'s data-tier
   residents, not every consumer. Documented here rather than guessed silently.

2. **`app_net` is `internal: true`**, which the brief did not explicitly ask for (only `data_net`
   was). None of `app_net`'s members (`app`, `queue`, `scheduler`, `reverb`, `redis`, `openbao`)
   have a genuine need for direct Internet egress — `app` already gets egress through
   `edge_net`/`processing_net`/`browser_net`, and the other three are pure internal services. This
   was confirmed empirically: a container attached to both an internal and a non-internal network
   *does* get real Internet egress and *can* have host ports published — Docker's `internal` flag
   is per-network, not per-container — so leaving `app_net` non-internal would have silently
   undermined the isolation intent for `redis`/`openbao`. Per the coordinator's explicit
   instruction mid-task ("security-first over convenience"), this extra hardening was kept.

3. **`clamav` placed on `processing_net`**, which no network bullet in the brief mentioned at all.
   It needs outbound Internet for `freshclam` virus-definition updates (confirmed: ClamAV pulls
   `daily.cvd`/`main.cvd`/`bytecode.cvd` on startup) and needs to be reachable from `app`/
   `python-worker`, the plausible callers of AV scanning in the document-intake pipeline
   (`BLUEPRINT.md` §14.1). `processing_net` is the only non-internal network with those properties.

4. **`ext-pcntl` added to the PHP image**, beyond the brief's stated list (`pdo_pgsql`, `redis`,
   `bcmath`, `intl`). Not a guess — `apps/api/composer.lock` locks `laravel/horizon` 5.48.3, which
   hard-requires `ext-pcntl`, and `composer install` fails without it (real error, reproduced
   below). It ships in the PHP source tree like `bcmath`, so it needed no new apt package.

5. **OpenBao transit key type is `chacha20-poly1305`, not literally XChaCha20-Poly1305.**
   `BLUEPRINT.md` §4.2 prefers XChaCha20-Poly1305, but OpenBao/Vault's transit engine does not expose
   that construction — `chacha20-poly1305` (IETF, 96-bit nonce) is the closest available type and
   is what wraps/unwraps the per-subject DEK. Real XChaCha20-Poly1305 for application-level field
   encryption (`BLUEPRINT.md` §4.3) is a separate, later concern for whoever implements Milestone 1,
   and is unaffected by this KMS-layer choice.

6. **OpenBao runs in dev mode** (`bao server -dev`, in-memory storage, single unseal key, root
   token from an env var). This is explicitly what the brief asked for ("dev-mode transit
   engine") and is loudly marked DEV ONLY in `.env.example`, `compose.yaml`, and
   `docker/openbao/init.sh`. **Production must not use this** — it needs a real storage backend,
   auto-unseal, and scoped policies instead of a root token. Not built here; out of this task's
   scope per the brief.

7. **`edge` listens on unprivileged port 8080, not 80**, with `cap_drop: [ALL]` and no
   `NET_BIND_SERVICE` added back — avoids needing any capability at all for the non-root `nginx`
   user to bind. `compose.yaml` maps host `80` (`EDGE_HTTP_PORT`) to container `8080`.

8. **nginx's `fastcgi_pass` uses a `resolver` + variable, not a static upstream name.** A real
   boot test showed `fastcgi_pass app:9000;` makes nginx refuse to start at all
   ("host not found in upstream") if `app` isn't already running — which breaks independent
   startup ordering and survivability of `app` restarts. Using Docker's embedded DNS
   (`resolver 127.0.0.11`) with `set $upstream_app app:9000; fastcgi_pass $upstream_app;` defers
   resolution to request time.

9. **Postgres volume mounts `/var/lib/postgresql` (parent), not `/var/lib/postgresql/data`.**
   Postgres 18's own image changed convention to place data in a major-version subdirectory for
   `pg_upgrade` compatibility; mounting the old path works but prints a warning and (with a fresh
   volume) skips running `/docker-entrypoint-initdb.d/*` in one repro. Confirmed by a real side-by-
   side test.

10. **Narrow `cap_add` grants for Postgres and Redis**, not the images' default capability set.
    Both official images start as root and internally drop to a non-root user (`gosu`/`setpriv`) —
    with `cap_drop: [ALL]` and nothing added back, that drop itself fails ("Operation not
    permitted" / "apply bounding set: Operation not permitted"). Verified by testing incrementally:
    Postgres needs `CHOWN, SETUID, SETGID, DAC_OVERRIDE`; Redis additionally needs `SETPCAP`
    (its entrypoint uses `setpriv`, which needs `SETPCAP` to shrink its own bounding set). MinIO
    needed neither — it runs directly as a fixed non-root UID via `user:`, no privilege-drop step.

11. **`minio-init-perms` one-shot service.** MinIO's own image never chowns a fresh data volume
    for a non-root `user:` — confirmed by a real "file access denied" failure. A tiny
    `alpine:3.24.1` container chowns the named volume to `10001:10001` once before `minio` starts
    (`depends_on: condition: service_completed_successfully`), keeping MinIO itself non-root and
    `cap_drop: [ALL]`.

12. **`python-worker` has a placeholder `command`.** `apps/processing` currently only contains the
    shared FastAPI app (`python-api`'s own code) — there is no distinct worker entrypoint yet. The
    command prints a message and sleeps rather than either (a) silently running the web server
    under a misleading name or (b) inventing a fake worker loop. See "What is NOT done."

13. **`composer dump-autoload` runs in a separate step *after* both `vendor/` and the application
    source land in the image**, not folded into the `vendor` stage's `composer install
    --no-autoloader`. Laravel's PSR-4 map spans both `vendor/` and `app/`/`database/factories/` —
    generating the autoloader before `app/` exists produces a `vendor/autoload.php` that Laravel
    can't boot with. Confirmed: `php artisan --version` failed with "Failed opening required
    vendor/autoload.php" before this fix, succeeded ("Laravel Framework 13.30.1") after.

14. **Runtime shared libraries added explicitly to the PHP `runtime` stage**
    (`libpq5`, `libicu76`), separate from the `-dev` headers used to *compile* `pdo_pgsql`/`intl`
    in `ext-builder`. Copying only the compiled `.so` extension files across stages, without their
    own native library dependencies, produced silent "Unable to load dynamic library" warnings at
    boot (PHP still started — a healthcheck alone would not have caught this; only a real
    `php -m` check surfaced it).

## How to verify

All commands below were actually run during this session; output is real, not illustrative.

**Config parses cleanly:**
```
$ docker compose config --quiet && echo OK
OK
```

**`vector`/`unaccent`/`pg_trgm`/`romanian` on the real Postgres image:**
```
$ docker compose exec -T postgres psql -U taxos -d taxos -c \
    "SELECT extname FROM pg_extension; SELECT cfgname FROM pg_ts_config WHERE cfgname='romanian';"
 extname
----------
 plpgsql
 vector
 unaccent
 pg_trgm
(4 rows)

 cfgname
----------
 romanian
(1 row)
```

**Data layer brought up for real** (postgres, redis, minio, openbao — `app`/`queue`/`reverb` were
*not* started per the brief, though see the note above about `apps/api` landing mid-task):
```
$ docker compose up -d postgres redis minio-init-perms minio openbao openbao-init
$ docker compose ps
NAME              STATUS
anaf-minio-1      Up (healthy)
anaf-openbao-1    Up (healthy)
anaf-postgres-1   Up (healthy)
anaf-redis-1      Up (healthy)
```

**OpenBao transit + KEK, including after a real restart (dev-mode storage is in-memory, so
`openbao-init` re-running is the expected recovery path, and it is idempotent):**
```
$ docker compose exec ... openbao bao read transit/keys/tax-subject-kek
name    tax-subject-kek
type    chacha20-poly1305
...
```

**PHP runtime image, built and boot-tested against the real `apps/api`:**
```
$ docker build -f docker/php/Dockerfile --target runtime -t taxos/php-runtime:test .
   ... (builds clean)
$ docker run --rm taxos/php-runtime:test php artisan --version
Laravel Framework 13.30.1
$ docker run --rm taxos/php-runtime:test php -m | grep -Ei 'pdo_pgsql|redis|bcmath|intl|pcntl|sodium'
bcmath
intl
pcntl
pdo_pgsql
redis
sodium
```
Also verified with `--read-only --tmpfs /tmp`, running as `appuser` (uid 10001), and the
`pm.status_path=/fpm-status` healthcheck answering over real FastCGI via `cgi-fcgi`.

**Edge image, self-contained health check, and the DNS-at-request-time fix:**
```
$ docker run -d --read-only --tmpfs ... -p 18888:8080 taxos/edge:test
$ curl http://localhost:18888/healthz
ok
$ curl -o /dev/null -w '%{http_code}\n' http://localhost:18888/index.php
502   # correct: resolves `app` at request time, fails cleanly because no app container exists
       # in this standalone test — a static fastcgi_pass would have crashed nginx at startup instead
```

**Python image, built and run against the real `apps/processing`:**
```
$ docker build -f docker/python/Dockerfile -t taxos/python:test .
$ docker run --read-only --tmpfs /tmp -p 18000:8000 taxos/python:test \
    uvicorn processing.main:app --host 0.0.0.0 --port 8000
$ curl http://localhost:18000/health
{"status":"ok","service":"processing"}
```

**`make test`/`make lint` machinery, run against real dev-dependency images:**
```
$ docker build -f docker/php/Dockerfile --target test -t taxos/php-test:test .
$ docker run --rm taxos/php-test:test vendor/bin/pint --test
  PASS ... 27 files
$ docker run --rm taxos/php-test:test vendor/bin/phpstan analyse
 [OK] No errors
$ docker build -f docker/python/Dockerfile --target test -t taxos/python-test:test .
$ docker run --rm taxos/python-test:test pytest
21 passed in 1.24s
$ docker run --rm taxos/python-test:test ruff check src tests
All checks passed!
$ docker run --rm taxos/python-test:test pyright
0 errors, 0 warnings, 0 informations
```

**Postgres/Redis/MinIO have no host ports and no outbound Internet in the production shape**
(`compose.yaml` alone, no override):
```
$ docker compose -f compose.yaml config | grep -A2 "postgres:$" | grep ports   # (no output)
$ docker network inspect anaf_data_net --format '{{.Internal}}'
true
$ docker network inspect anaf_app_net --format '{{.Internal}}'
true
$ docker network inspect anaf_processing_net --format '{{.Internal}}'
false
```

**A container on only an internal network cannot have a published port reached from the host**
(the reason `compose.override.yaml`'s dev port publishing needs its own `dev_net`):
```
$ docker network create --internal testnet && docker run -d --network testnet -p 18080:80 nginx:1.30.4-trixie
$ curl http://localhost:18080/    # times out / connection refused
```

**No container mounts `/var/run/docker.sock`** — grepped the final `docker compose config` output;
no matches.

## Interfaces exposed to other modules

- **Network names** (Docker DNS, usable as hostnames from any container sharing a network):
  `edge`, `app`, `queue`, `scheduler`, `reverb`, `python-api`, `python-worker`, `spv-browser`,
  `clamav`, `postgres`, `redis`, `minio`, `openbao`.
- **`app` container contract:** FastCGI on `:9000`; `pm.status_path=/fpm-status`,
  `ping.path=/fpm-ping` for healthchecks/monitoring; runs as uid/gid `10001`; root filesystem is
  read-only — anything apps/api needs to write at runtime beyond `storage/` and
  `bootstrap/cache/` (already volume-mounted) needs a new named volume added here, not a workaround
  in application code.
- **`python-api`/`python-worker` contract:** share one image; `python-api` runs
  `uvicorn processing.main:app` on `:8000` with a `/health` route (already real and passing);
  `python-worker` has no real command yet (see below) — whoever adds one only needs to change its
  `command:` in `compose.yaml`, the image is already built to support it. Neither container
  receives any `DB_*`/`POSTGRES_*` environment variable — by design, not oversight.
- **`spv-browser` contract:** expected to expose an HTTP control API per `BLUEPRINT.md` §13.4
  (`POST /v1/spv/sessions`, etc.) on `:3000`, including `GET /healthz`. Its Chromium launch code
  **must** pass `--no-sandbox` — the container has `cap_drop: [ALL]` with no `SYS_ADMIN`, so
  Chromium's own internal sandbox cannot initialize otherwise.
- **OpenBao KMS contract:** transit engine at `transit/`, key name `tax-subject-kek`
  (overridable via `TAXOS_KEK_NAME` in `docker/openbao/init.sh`), type `chacha20-poly1305`.
  Whoever implements envelope encryption (Milestone 1) talks to `http://openbao:8200` with a
  scoped token — the dev root token in `.env.example` is not that token and must never be used
  outside a developer's own machine.
- **Object storage contract:** bucket `taxos-documents` (referenced in env, not yet created — no
  agent has run `mc mb` or equivalent; whoever builds the Documents module needs to create it,
  e.g. via a small init step following the `minio-init-perms`/`openbao-init` pattern already
  established here).
- **Postgres:** database `taxos`, extensions `vector`/`unaccent`/`pg_trgm` already present,
  `romanian` text-search config confirmed built in. Migrations run via
  `docker compose exec app php artisan migrate` once `app` is actually started.

## What is NOT done

- **`app`/`queue`/`scheduler`/`reverb` were not started as running compose services** in this
  session, per the explicit instruction not to (even though `apps/api` landed mid-task and the
  images now build and boot correctly in standalone `docker run` tests — see "How to verify"). The
  next agent should run `docker compose up -d app queue scheduler reverb` for the first true
  in-compose verification, including their `depends_on` ordering and the `edge` → `app` FastCGI
  path end to end.
- **`scheduler`'s and `reverb`'s healthchecks are weak.** `scheduler` only checks that the
  `schedule:work` process exists (`pgrep`), not that it is actually ticking — there is no
  Laravel-side heartbeat yet. `reverb`'s healthcheck only proves the WebSocket port accepts a raw
  TCP connection — Reverb exposes no documented HTTP health route as of this check. Both should be
  revisited once apps/api adds a real heartbeat/health mechanism for them.
- **`python-worker` has no real entrypoint.** `apps/processing` only contains the FastAPI app so
  far; `python-worker`'s `command:` in `compose.yaml` is an explicit, self-describing placeholder
  (prints a message, sleeps) rather than a fabricated worker loop. Its healthcheck is a bare
  `pgrep`.
- **`docker/playwright/Dockerfile` is unbuildable right now** — `apps/spv-browser` is completely
  empty (no `package.json`). The Dockerfile is written against the documented contract (BLUEPRINT.md
  §13.4) and the base image/account (`pwuser`) are verified real, but the `npm ci`/`npm run build`
  steps and the `/healthz` healthcheck are unverified assumptions.
- **`edge`'s Docker image bakes in `apps/api/public` at build time**; it does not run Vite. Nobody
  has run `npm run build` in `apps/api` yet, so `apps/api/public/build/*` (the hashed Vite assets)
  does not exist — the edge image will build and serve `index.php`/static files that already exist
  under `public/`, but any page referencing `@vite()`-generated assets will 404 until that build
  step runs once, upstream of `docker build`.
- **No TLS anywhere.** `edge` serves plain HTTP on 8080/host-80 only. Out of scope for this
  topology task but will need a real ADR before anything public-facing ships.
- **No object storage bucket created yet** (`taxos-documents`) — see "Interfaces" above.
- **Production digest pinning is documented, not applied.** `compose.yaml` has a commented example
  showing the `image@sha256:…` form (§3.4) with a real, verified digest, but the file itself still
  uses tags — digest-pinning the whole file is a deploy-pipeline decision for whoever builds that,
  not a dev-shape default.
- **No OpenTelemetry/Prometheus/Grafana/Loki/Tempo/Sentry** — explicitly marked optional in
  `BLUEPRINT.md` §3.1 for this milestone; not built.
- **`docker/openbao/init.sh` is not registered anywhere as a systemd/cron/etc. recovery hook** —
  it re-runs correctly on every `docker compose up`, which is sufficient for dev-mode's
  wipe-on-restart behavior, but a production OpenBao (not dev mode) would use a different, durable
  bootstrap process entirely.

## Open questions

1. Should `queue`/`scheduler` eventually need direct HTTP access to `python-api` (e.g., for
   synchronous OCR dispatch), or does all Laravel→Python traffic go through Redis-backed queues
   that Python consumes directly? This determines whether `queue` ever needs `processing_net`.
   Not decided here — deliberately left to whoever implements that job-dispatch flow, since it's
   an application-layer decision, not a topology one.
2. What is `python-worker`'s real entrypoint going to be (Celery/arq/RQ/a custom loop)? Needed
   before its placeholder `command:` in `compose.yaml` can be replaced with something real.
3. Confirm with whoever writes `apps/spv-browser/package.json` that its `playwright` npm dependency
   is pinned to exactly `1.63.0` — a version mismatch against the pre-installed browser binaries in
   `mcr.microsoft.com/playwright:v1.63.0-noble` is a common, hard-to-diagnose failure mode.
4. Should `mailpit` (or an equivalent) exist in a staging/production compose overlay too, or is
   `MAIL_MAILER` expected to point at a real SMTP relay there via its own overlay file that doesn't
   exist yet? Not decided.

## Sources consulted

Facts already in `docs/reference/verified-versions.md` were used as given, not re-verified. Facts
below were newly needed for this task and independently verified (`docker pull` + real container
runs) on 2026-09-07/2026-09-08, since they weren't already in that file:

- nginx exact tags — `https://hub.docker.com/v2/repositories/library/nginx/tags` →
  `nginx:1.30.4-trixie` (stable branch, latest patch at check time), digest
  `sha256:d5792f71a9496b833bc08ea834a758c46e2b6a6306c10f4be926f38a656cdc1c`.
- MinIO exact tag — `https://hub.docker.com/v2/repositories/minio/minio/tags` →
  `minio/minio:RELEASE.2025-09-07T16-13-09Z` (confirms `verified-versions.md`'s note that MinIO's
  last release was 2025-09-07), digest
  `sha256:14cea493d9a34af32f524e538b8346cf79f3321eff8e708c1e2960462bd8936e`.
- mailpit exact tag — `https://hub.docker.com/v2/repositories/axllent/mailpit/tags` →
  `axllent/mailpit:v1.31.1`, digest
  `sha256:98b916bd3c8d61f7633a52d3ea2f58d00620cb01ca57ab59edde68c347a95365`.
- Playwright exact tag — `https://mcr.microsoft.com/v2/playwright/tags/list` →
  `mcr.microsoft.com/playwright:v1.63.0-noble` (Node 24.20.0, npm 11.19.0, Ubuntu 24.04 Noble
  inside), digest `sha256:eff16c30e6f3f4af0a03fa4b706120d5e9b0891c344a27d64559aff5900a4a27`; has a
  pre-created non-root `pwuser` account (uid/gid 1001), confirmed by `id pwuser` inside the image.
- Composer exact tag — `https://hub.docker.com/v2/repositories/library/composer/tags` →
  `composer:2.10.3`.
- uv exact version — `https://api.github.com/repos/astral-sh/uv/releases/latest` → `0.12.10`
  (published 2026-09-04); static binary copied from `ghcr.io/astral-sh/uv:0.12.10` per Astral's own
  documented Docker pattern.
- pecl `redis` extension exact version — `https://pecl.php.net/rest/r/redis/allreleases.xml` →
  `6.3.0` (latest stable).
- apt package candidate versions on `php:8.5-fpm-trixie`/`nginx:1.30.4-trixie`/
  `python:3.14-slim-trixie` (all via `apt-cache policy` inside the real base images, 2026-09-07):
  `libpq-dev=17.11-0+deb13u1`, `libicu-dev=76.1-4`, `libpq5=17.11-0+deb13u1`,
  `libicu76=76.1-4`, `unzip=6.0-29+deb13u1`, `libfcgi-bin=2.4.5-0.1`, `curl=8.14.1-2+deb13u4`
  (nginx image), `libatomic1=14.2.0-19` (python image, needed by pyright's bundled Node runtime).
- `alpine:3.24.1` — pinned exact patch version resolved by `alpine:latest` at check time, confirmed
  via `docker pull alpine:3.24.1` and digest `sha256:28bd5fe8b56d1bd048e5babf5b10710ebe0bae67db86916198a6eec434943f8b`.
- All image digests recorded here were read directly off `docker inspect --format '{{index
  .RepoDigests 0}}'` for the pulled images on 2026-09-07/08, not looked up separately.
- Postgres 18 volume-path convention change — observed directly from the container's own startup
  log ("The suggested container configuration for 18+ is to place a single mount at
  `/var/lib/postgresql`...", with a link to `github.com/docker-library/postgres/issues/37`), not
  from an external doc lookup.
- Everything else in "Key decisions" above was established by running the actual containers, not
  by reading documentation — see "How to verify" for the exact commands.
