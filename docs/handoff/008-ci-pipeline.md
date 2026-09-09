# 008 — CI pipeline

**Milestone:** M0   **Status:** complete
**Date:** 2026-09-08

## What was built

A single GitHub Actions workflow, `.github/workflows/ci.yml`, that mechanically enforces the gates
`BLUEPRINT.md` §22 and `docs/AGENTS.md` §7 already state as binding but that nothing was running. It
covers PHP, JS/TS (two packages), Python, container build + SBOM + vulnerability scan, a repo-wide
secret scan, and a wired-but-honest placeholder for the fiscal gates that don't exist yet
(Milestones 4/5).

Everything in it is a single workflow file on purpose: the fail-fast ordering this task asked for
("cheap lanes before expensive ones") is expressed with `needs:`, and `needs:` only works between
jobs in the same workflow file. Splitting PHP/JS/Python/containers into separate files would have
silently dropped that ordering guarantee — `containers` would start building four Docker images in
parallel with a broken Pint run instead of waiting for it.

Entry points:
- `.github/workflows/ci.yml` — the pipeline. Triggers on `push`/`pull_request` to `main` **and**
  `master` (see **Open questions** — the repo has no commits yet, so the eventual default branch
  name isn't settled), plus `workflow_dispatch`.
- `.github/scripts/fiscal-gates-placeholder.sh` — the fiscal-gates stub, self-contained, runnable
  standalone (`bash .github/scripts/fiscal-gates-placeholder.sh`) without any CI context.
- `.github/gitleaks/.gitleaksignore` — the two verified false-positive suppressions the secret-scan
  job needs (see **Key decisions**).

## Files created / changed

- `.github/workflows/ci.yml` — new. Eight jobs: `secret-scan`, `fiscal-gates`, `php-quality`,
  `php-tests`, `js-api`, `js-spv-browser`, `python`, `containers` (a 4-way matrix).
- `.github/scripts/fiscal-gates-placeholder.sh` — new. See **What is NOT done** for exactly what it
  does and does not prove.
- `.github/gitleaks/.gitleaksignore` — new. Two fingerprinted suppressions, each hand-verified and
  commented (see **Key decisions** point 4).
- `docs/STATUS.md` — added three rows to **Known issues** (real, fixable HIGH-severity CVEs the
  vulnerability-scan gate found in images built by other agents' Dockerfiles — not touched, per
  this task's scope and per the explicit instruction to record rather than fix). This handoff note.
- Nothing under `apps/**`, `docker/**`, or `compose*.yaml` was modified. Three other agents'
  in-flight work (`apps/api/app/Support/Crypto/**`, `apps/api/app/Domain/Audit/**`,
  `apps/processing/pyproject.toml`) landed live in the repo while this task was in progress and was
  read, never edited.

## Key decisions

1. **One workflow file, not five.** See "What was built" — `needs:` cannot cross workflow files.
   `secret-scan` and `fiscal-gates` carry no `needs` (they're cheap and independent); `php-tests`
   needs `php-quality`; `containers` needs all five lint/test jobs. This is the literal mechanism
   behind "fail fast on the cheap lanes before the expensive ones."

2. **Every third-party action is pinned to a commit SHA**, not just a version tag, with a trailing
   `# vX.Y.Z` comment — one step stricter than `docs/DEPENDENCY-POLICY.md`'s stated minimum
   ("`actions/checkout@v5.0.0` style **or** a commit SHA"). Every SHA was resolved from the GitHub
   API and re-verified after the file was written (see **How to verify**) — this caught one real
   mistake: `actions/cache` was initially pinned to a fabricated/transposed SHA that didn't match
   `v6.1.0` at all. Two others (`anchore/sbom-action@v0.24.2`, `aquasecurity/trivy-action@v0.36.0`)
   use **annotated** tags, where `git/ref/tags/<tag>` returns a tag object SHA, not a commit SHA —
   those needed one more dereference (`git/tags/<tag-object-sha>` → commit). Both are documented in
   the verification transcript below so the next agent doesn't "fix" them back to the wrong value.

3. **Secret scanning runs `zricethezav/gitleaks` via a pinned `docker run`, not the
   `gitleaks/gitleaks-action` GitHub Action.** The Action gates paid features behind a license key
   for some usage patterns; the OSS CLI itself has no such gate. Running the image directly is also
   simpler to pin (one image tag) and easier to verify locally (see **How to verify** — this is
   exactly the invocation that was tested).

4. **`.github/gitleaks/.gitleaksignore` suppresses exactly two findings**, both hand-verified as
   real false positives, not credentials:
   - `apps/api/app/Support/Crypto/EnvelopeCipher.php:generic-api-key:43` — the PHP/libsodium
     constant name `SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES`, flagged purely on
     entropy.
   - `docker/openbao/init.sh:generic-api-key:17` — a comment naming the OpenBao/Vault transit
     algorithm `chacha20-poly1305`, not a key or token value.
   The fingerprints are **repository-relative paths** (`apps/api/...`, `docker/...`), not absolute
   paths. This is deliberate and was the second thing this task got wrong before fixing it: gitleaks
   embeds whatever path prefix it was invoked with into the fingerprint it prints, so an
   absolute-path fingerprint captured against a local `/repo`-mounted test would never match a real
   GitHub Actions runner's checkout path (`/home/runner/work/...`). The workflow's `gitleaks` step
   mounts the checkout at `/workspace`, sets `-w /workspace`, and scans target `.` — exactly the
   invocation that produces the portable, repo-relative fingerprints the ignore file contains. Do
   not change that mount/workdir/target combination without re-verifying the ignore file still
   matches.

5. **Trivy's vulnerability gate uses `--severity CRITICAL,HIGH --ignore-unfixed`, and this is a
   real, currently-failing gate, not a tuned-to-pass one.** Scanning the four images this workflow
   actually builds found three real, currently-unfixed-in-this-repo HIGH findings with an available
   upstream fix (msgpack in the Python image, libde265 in the nginx base image, brace-expansion in
   the spv-browser image — see `docs/STATUS.md` Known issues #1–#3 and **How to verify** for exact
   CVEs). `ignore-unfixed: true` was a deliberate, documented choice (an OS package CVE with no
   patch yet published is not something any code change in this repo can resolve, and would make
   the gate permanently unsatisfiable) — it was **not** used to hide these three findings, which are
   all fixable and are exactly what the gate is designed to catch. **The very first real run of this
   pipeline will fail `containers (python-runtime)`, `containers (edge)`, and
   `containers (spv-browser)`** until someone bumps the affected dependency/base image. This is
   intentional and is the correct behavior for a security gate that was asked to be honest rather
   than green — per this task's own instruction ("if a gate fails because of their in-flight work,
   record it... rather than editing their files"), those Dockerfiles were not touched.

6. **The `containers` job downloads `js-api`'s Vite build as an artifact rather than rebuilding
   it.** `docker/nginx/Dockerfile` bakes in `apps/api/public`, including the gitignored
   `public/build/` Vite output — a second runner building the `edge` image has no way to produce
   that directory itself without either running `npm run build` a second time (redundant, and a
   second opportunity for the build to silently drift from what `js-api` actually tested) or
   downloading the one artifact already produced and verified. `actions/upload-artifact` /
   `actions/download-artifact` handle this; `if-no-files-found: error` on the upload step turns a
   silently-empty Vite build into a hard failure instead of a confusing missing-assets bug three
   jobs later.

7. **`containers` is a 4-entry matrix** (`php-runtime`, `python-runtime`, `edge`, `spv-browser`),
   each building its Dockerfile's `runtime` target (or, for `edge`/`spv-browser`, the Dockerfile's
   only stage — see `matrix.target: ""`, confirmed by reading `docker/build-push-action@v7.3.0`'s
   own source that an empty `target` input omits `--target` entirely rather than erroring or
   defaulting to something wrong). `fail-fast: false` so one broken image doesn't hide findings in
   the other three. Never the `test` stage of `docker/php/Dockerfile` / `docker/python/Dockerfile`
   — dev tooling (Pint, PHPStan, pytest, ruff, pyright, pip-audit) is not shipped and is not what
   gets scanned; `php-quality`/`php-tests`/`python` already run those tools directly on the runner
   (via `shivammathur/setup-php` / `astral-sh/setup-uv`), which is also faster than building a Docker
   image first.

8. **`php-tests` needs `touch .env` before running Pest, and this is now a documented, permanent
   step, not a one-off local workaround.** Laravel's dotenv loader emits a PHP warning (which Pest
   turns into a test **WARN**, not a clean PASS) when no `.env` file exists at all, even though
   every value it would read is supplied via real environment variables in the job. Verified locally
   (see below): identical `pest` invocation goes from "2 warnings (9 assertions)" to "2 passed (9
   assertions)" with only that one line added. No `.env` is committed (`BLUEPRINT.md` §4.10) and none
   ships in the runtime image, so this has to happen in the CI job itself.

9. **A single non-secret, hardcoded `APP_KEY`** (`base64:8nv5Q...`) is used in the two jobs that
   boot the Laravel app (`php-quality`'s PHPStan step, `php-tests`). It is a properly-shaped
   AES-256 key (required — an arbitrary or wrong-length string throws
   `RuntimeException: Unsupported cipher or incorrect key length`, which is exactly what happened on
   the first attempt using the Makefile's existing placeholder value, which is not a valid key
   despite looking like one). It encrypts nothing that outlives the job and is not read from any
   secret store — hardcoding it in the workflow file is correct, not a shortcut.

10. **`js-api` has no test step, and this is a documented omission, not a stub.** `BLUEPRINT.md` §22
    asks for "frontend tests." `apps/api/resources/js` has no test files yet and `package.json` has
    no `test` script — M0 shipped one static page (`docs/handoff/002-laravel-scaffold.md`). Per
    `docs/AGENTS.md` ("if a gate genuinely can't run yet, leave it out and document why rather than
    faking a pass"), no step was added. Add it back — right after `npm run build`, same job — the
    moment a real test file and `test` script exist.

## How to verify

Every command below was run for real during this session against the actual repo content at the
time (which changed mid-session — three other agents' work landed live; see "Files created /
changed"). This is the distinction the task asked for explicitly: **the individual commands below
are verified with real, pasted output. The pipeline itself — the YAML, the `needs:` graph, the
service containers, the GitHub-hosted-runner behavior of `docker/build-push-action`'s `type=gha`
cache — cannot be executed outside GitHub Actions and is unverified until the first real push.**

**YAML/Action syntax:**
```
$ python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))"
YAML parses OK

$ actionlint .github/workflows/ci.yml
(no output — exit 0)
```
(Two real bugs were caught this way and fixed before the file was finalized: a job-level `env:`
block illegally referencing the workflow-level `env` context — `env` is not an available context
there per GitHub's own context-availability table — and are documented above, point 2's fabricated
SHA. Both fixes are in the final file.)

**Every pinned action SHA, re-verified against the GitHub API after the file was written:**
```
$ for spec in actions/checkout:v7.0.1 actions/setup-node:v7.0.0 astral-sh/setup-uv:v10.0.1 \
    shivammathur/setup-php:2.37.2 actions/cache:v6.1.0 actions/upload-artifact:v7.0.1 \
    actions/download-artifact:v8.0.1 docker/setup-buildx-action:v4.3.0 \
    docker/build-push-action:v7.3.0; do ...compare pinned SHA in ci.yml against
    api.github.com/repos/$repo/git/ref/tags/$tag... done
actions/cache@v6.1.0 -> pinned=55cc8345863c7cc4c66a329aec7e433d2d1c52a9 MATCH
actions/checkout@v7.0.1 -> pinned=3d3c42e5aac5ba805825da76410c181273ba90b1 MATCH
actions/download-artifact@v8.0.1 -> pinned=3e5f45b2cfb9172054b4087a40e8e0b5a5461e7c MATCH
actions/setup-node@v7.0.0 -> pinned=820762786026740c76f36085b0efc47a31fe5020 MATCH
actions/upload-artifact@v7.0.1 -> pinned=043fb46d1a93c77aae656e7c1c64a875d1fc6a0a MATCH
astral-sh/setup-uv@v10.0.1 -> pinned=20cfd1bf945f4377ade1205e4dbc17946fc9a30d MATCH
docker/build-push-action@v7.3.0 -> pinned=53b7df96c91f9c12dcc8a07bcb9ccacbed38856a MATCH
docker/setup-buildx-action@v4.3.0 -> pinned=37fe631027851001ddb9b187196cc803df7f5f0e MATCH
shivammathur/setup-php@2.37.2 -> pinned=f3e473d116dcccaddc5834248c87452386958240 MATCH

# anchore/sbom-action@v0.24.2 and aquasecurity/trivy-action@v0.36.0 use annotated tags —
# the direct ref lookup returns a tag object, dereferenced one level further:
anchore/sbom-action tag-obj=006b7ce8314066bdf1765b4500370d40fa6917a3 -> commit=3ad7283483fc7af8ff2b4ea19663c2d5ca935e26  (matches ci.yml)
aquasecurity/trivy-action tag-obj=a9c7b0f06e461e9d4b4d1711f154ee024b8d7ab8 -> commit=ed142fd0673e97e23eac54620cfb913e5ce36c25  (matches ci.yml)
```

**PHP quality gates** (host PHP is 8.3; ran inside the real `docker/php/Dockerfile` `test` stage,
built fresh — same as `docs/handoff/002-laravel-scaffold.md`'s approach):
```
$ docker build -f docker/php/Dockerfile --target test -t taxos-php-test:ci .   # builds clean

$ docker run --rm taxos-php-test:ci composer validate --strict
./composer.json is valid

$ docker run --rm taxos-php-test:ci composer audit
No security vulnerability advisories found.

$ docker run --rm taxos-php-test:ci vendor/bin/pint --test
PASS ... 27 files

$ docker run --rm -e APP_KEY=base64:... taxos-php-test:ci vendor/bin/phpstan analyse --memory-limit=512M
[OK] No errors

$ docker run --rm taxos-php-test:ci vendor/bin/deptrac analyse --config-file=deptrac.php
Violations 0, Skipped violations 0, Uncovered 0, Warnings 0, Errors 0
```

**PHP tests against real Postgres + Redis** (`pgvector/pgvector:pg18-trixie`, `redis:8-trixie` —
same images/tags the workflow's `services:` block uses):
```
$ docker network create anaf-ci-test
$ docker run -d --name anaf-ci-pg --network anaf-ci-test -e POSTGRES_DB=taxos \
    -e POSTGRES_USER=taxos -e POSTGRES_PASSWORD=taxos pgvector/pgvector:pg18-trixie
$ docker run -d --name anaf-ci-redis --network anaf-ci-test redis:8-trixie

# First attempt, no .env file present, valid APP_KEY:
$ docker run --rm --network anaf-ci-test -e APP_KEY=base64:8nv5Q... -e APP_ENV=testing \
    -e DB_CONNECTION=pgsql -e DB_HOST=anaf-ci-pg ... taxos-php-test:ci \
    sh -lc 'php artisan migrate --force && vendor/bin/pest'
Tests: 2 warnings (9 assertions)   # file_get_contents(/var/www/html/.env): No such file or directory

# Same invocation, with `touch .env` added (see Key decisions #8):
$ docker run --rm --network anaf-ci-test ... taxos-php-test:ci sh -lc 'touch .env && vendor/bin/pest'
Tests: 2 passed (9 assertions)
```

**JS/TS — apps/api:**
```
$ npm ci                                    # added 183 packages, 0 vulnerabilities
$ npx eslint resources/js                   # clean
$ npx prettier --check resources/js         # All matched files use Prettier code style!
$ npx tsc --noEmit                          # clean
$ npm run build                             # ✓ 567 modules transformed, built in 265ms
$ npm audit --audit-level=high              # found 0 vulnerabilities
```

**JS/TS — apps/spv-browser:**
```
$ npm ci                                    # added 175 packages, 0 vulnerabilities
$ npx eslint .                              # clean
$ npx tsc --noEmit                          # clean
$ npm run build                             # tsc -p tsconfig.build.json — clean
$ npm test                                  # tests 22, pass 22, fail 0
$ npm audit --audit-level=high              # found 0 vulnerabilities
```

**Python — apps/processing:**
```
$ uv lock --check       # Resolved 58 packages in 1ms
$ uv sync --frozen       # (installs `processing` + deps into .venv)
$ uv run ruff format --check .   # 16 files already formatted
$ uv run ruff check .            # All checks passed!
$ uv run pyright                 # 0 errors, 0 warnings, 0 informations
$ uv run pytest -q               # 21 passed
$ uv run pip-audit               # No known vulnerabilities found
```

**Container builds** (all four images this workflow's `containers` matrix builds, from the real
Dockerfiles, unmodified):
```
$ docker build -f docker/php/Dockerfile --target runtime -t taxos-php-runtime:ci .      # OK
$ docker build -f docker/python/Dockerfile --target runtime -t taxos-python-runtime:ci . # OK
$ npm run build   # (in apps/api — produces public/build/, which the edge image bakes in)
$ docker build -f docker/nginx/Dockerfile -t taxos-edge:ci .                              # OK
$ docker build -f docker/playwright/Dockerfile -t taxos-spv-browser:ci .                  # OK
```
(`docker/playwright/Dockerfile` was recorded as unbuildable in `docs/handoff/001-docker-topology.md`
because `apps/spv-browser` didn't exist yet at that point in the session. It exists now, from
`docs/handoff/004-python-browser-services.md`, and the image builds cleanly — this is the first
successful build of it.)

**SBOM generation** (`anchore/syft:v1.51.1` — pinned image, same as `anchore/sbom-action@v0.24.2`
uses internally):
```
$ docker run --rm -v /var/run/docker.sock:/var/run/docker.sock anchore/syft:v1.51.1 \
    docker:taxos-php-runtime:ci -o spdx-json > sbom.json    # (repeated for all 4 images)
exit=0 for all four; each produced a non-empty SPDX JSON document.
```

**Vulnerability scan** (`aquasec/trivy:0.74.0` — pinned image, same tool `aquasecurity/trivy-
action@v0.36.0` wraps):
```
$ docker run --rm -v /var/run/docker.sock:/var/run/docker.sock aquasec/trivy:0.74.0 image \
    --scanners vuln --severity CRITICAL,HIGH --ignore-unfixed --exit-code 1 --format table <image>

taxos-php-runtime:ci      exit=0   (clean)
taxos-python-runtime:ci   exit=1   Total: 2 (HIGH: 2) — msgpack 1.1.2, GHSA-6v7p-g79w-8964
taxos-edge:ci             exit=1   Total: 1 (HIGH: 1) — libde265-0, CVE-2026-33164
taxos-spv-browser:ci      exit=1   Total: 4 (HIGH: 4) — brace-expansion 5.0.7, CVE-2026-14257 (+3 more instances of the same finding)
```
These three findings are recorded in `docs/STATUS.md` Known issues #1–#3, not fixed here (outside
this task's file scope — see **Key decisions** #5).

**Secret scan** (`zricethezav/gitleaks:v8.30.1` — the exact invocation `ci.yml` uses):
```
# Against only the files `git add -A -n` would actually track (i.e., what a real CI checkout sees —
# NOT the raw working directory, which still has .env/vendor/node_modules with real dev secrets and
# vendored noise neither committed nor ever pushed):
$ git add -A -n | sed -n "s/^add '\(.*\)'$/\1/p" > tracked-files.txt   # 194 files
$ rsync -a --files-from=tracked-files.txt . clean-checkout/
$ cd clean-checkout && docker run --rm -v "$(pwd)":/workspace:ro \
    -v .../.github/gitleaks:/gcfg:ro -w /workspace \
    zricethezav/gitleaks:v8.30.1 dir . --no-banner --gitleaks-ignore-path=/gcfg/.gitleaksignore
INF no leaks found                          # exit 0

# Canary — proves the gate isn't just blind, not merely "no output":
$ echo '-----BEGIN RSA PRIVATE KEY-----...' >> docs/README.md   # (in the clean checkout copy only)
$ docker run ... zricethezav/gitleaks:v8.30.1 dir . ...
WRN leaks found: 1   RuleID: private-key   File: docs/README.md   # exit 1
```

**Fiscal gates placeholder:**
```
$ bash .github/scripts/fiscal-gates-placeholder.sh
=== Fiscal gates: PLACEHOLDER — not yet implemented (Milestone 4/5) ===
No ruleset manifests (tax-rules/ro/**/*.yaml) and no TaxEngine implementation
(apps/api/app/Domain/TaxEngine/**) exist yet — Milestone 4/5 not started.
This placeholder passes trivially. ...
exit=0
```
(Not separately re-tested here, but by construction: if either `tax-rules/**/*.yaml` or
`apps/api/app/Domain/TaxEngine/**/*.php` existed, the two `find` commands would be non-empty and the
script's `if` branch would `exit 1` with the explanatory message instead — read the script, it's
short and linear.)

## Interfaces exposed to other modules

- **`apps/api/deptrac.php`** — every new `app/Domain/<Module>` needs a layer added here in the same
  change (already documented in `docs/handoff/002-laravel-scaffold.md`); `php-quality`'s Deptrac
  step will otherwise silently not check it, per that config file's own comment.
- **`apps/api/phpstan.neon`'s `parameters.paths`** — same story for PHPStan; `php-quality` runs
  whatever that file lists.
- **`frontend-build` artifact** — produced by `js-api`, 1-day retention, consumed by
  `containers (edge)`. Any future job that needs the built frontend (e.g. an eventual E2E job)
  should download this artifact rather than rebuilding.
- **`.github/gitleaks/.gitleaksignore`** — the only place to suppress a confirmed gitleaks false
  positive. Adding a line here requires (a) the exact fingerprint gitleaks itself prints when run
  with the workflow's mount/workdir/target combination (see Key decisions #4 — do not hand-type an
  absolute-path fingerprint) and (b) a comment explaining why it is not a real secret.
- **`.github/scripts/fiscal-gates-placeholder.sh`** — replace or extend this, not the workflow job
  that calls it, once Milestone 4 (ruleset manifests under `tax-rules/ro/<year>/`) or Milestone 5
  (`apps/api/app/Domain/TaxEngine/**`) lands. The script already fails loudly if either appears
  without being upgraded first — see **What is NOT done**.
- **Matrix job names in `containers`** (`php-runtime`, `python-runtime`, `edge`, `spv-browser`) map
  1:1 to the Dockerfiles under `docker/*/Dockerfile` and their `runtime` targets. Adding a fifth
  production image (e.g. once `spv-browser` grows beyond one Dockerfile, or a dedicated
  `python-worker` image diverges from `python-api`) means adding one matrix entry, not a new job.

## What is NOT done

- **The fiscal gates are a placeholder, explicitly and by design** (task instruction: "stubbed but
  wired... do not fake a passing fiscal check"). `.github/scripts/fiscal-gates-placeholder.sh`
  currently only verifies that no ruleset manifests or TaxEngine code exist yet, and fails loudly if
  they do. **It performs none of the five checks `BLUEPRINT.md` §22 actually requires** once M4/M5
  land: ruleset schema validation, source-reference existence checks, date-range overlap validation,
  official ANAF golden-example tests, and historical regression across 2020–2026. Do not read a
  green `fiscal-gates` run as evidence any fiscal number is correct — read it only as "no fiscal code
  exists yet, or someone remembered to update this before adding it."
- **Three real, currently-open HIGH-severity CVEs will fail the `containers` job** the first time
  this pipeline actually runs (see Key decisions #5, `docs/STATUS.md` Known issues #1–#3). They are
  in Dockerfiles this task's scope did not permit touching (`docker/python/Dockerfile`,
  `docker/nginx/Dockerfile`, `docker/playwright/Dockerfile`, owned by the docker-topology and
  python-browser-services agents). Someone needs to either bump the affected dependency/base image
  tag, or — for `spv-browser` specifically — restructure `docker/playwright/Dockerfile` into a
  multi-stage build that excludes devDependencies from the shipped image (see `docs/STATUS.md`
  issue #3's root-cause note).
- **No E2E/browser test job.** `BLUEPRINT.md` §21.5 calls for a DOM-contract smoke test against the
  real SPV portal (without real data) and a sandbox/mock full-workflow test — neither exists because
  the underlying SPV automation (`apps/spv-browser`'s portal actions) is all `501 not_implemented`
  stubs as of `docs/handoff/004-python-browser-services.md` (Milestone 10). Add this job once there
  is real navigation code to test.
- **No `docker push`.** `containers` builds with `push: false, load: true` — images are built and
  scanned, never published anywhere. Publishing to a registry (with the digest-pinning `compose.yaml`
  already documents as a production concern, per `docs/handoff/001-docker-topology.md`) is a
  separate, later decision — this task's scope was CI gates, not a deploy pipeline.
- **No dependency-update bot** (Dependabot/Renovate) configuration. `docs/DEPENDENCY-POLICY.md` §6
  says lockfile changes "arrive by PR with CI green," which this pipeline now enforces once they
  arrive, but nothing here opens those PRs automatically. Out of this task's stated scope
  (`.github/workflows/**` for *this* CI pipeline task specifically named building the gates, not
  bot configuration) — flagging as a natural follow-up, not silently added.
- **The pipeline itself is unverified against real GitHub Actions.** Every individual command it
  runs was verified for real (see **How to verify**); the YAML graph, the `needs:` ordering, service
  container health-check timing, and `docker/build-push-action`'s `cache-from/to: type=gha` behavior
  can only be proven by an actual push to a repository with Actions enabled. This repo has no
  commits yet (`git status` shows "On branch master / No commits yet" — see **Open questions**).

## Open questions

1. **Default branch name.** The local repo has no commits and is on `master`; no ADR or doc commits
   to `main`. `ci.yml` triggers on both `[main, master]` to avoid silently never running — once the
   repo has a real remote and a settled default branch, drop the unused one from both `on.push` and
   `on.pull_request`.
2. **Should `docker/playwright/Dockerfile` become multi-stage** (build stage with devDependencies,
   slim runtime stage with `npm ci --omit=dev` only)? This would fix `docs/STATUS.md` Known issue #3
   and shrink the image (currently 3.66GB) substantially. Not this task's file scope to change —
   flagging for whoever owns `docker/`.
3. **Should the three currently-open container CVEs block the very next PR**, or does the project
   want a documented grace-period/waiver mechanism (`docs/DEPENDENCY-POLICY.md` mentions "waiver
   documented" for CI warnings generally, `BLUEPRINT.md` §22, but doesn't define the waiver mechanism
   itself)? This task did not invent one — the gate fails honestly today, per instruction, and a
   human/process decision on waivers is a separate concern from building the gate.
4. **Node version pin (`20.19.6`).** Chosen to match what `docs/handoff/004-python-browser-services.md`
   verified against real Playwright browser binaries, and what this session's host actually runs.
   Revisit if `apps/spv-browser`'s Playwright version ever moves off `1.63.0`.

## Sources consulted

- `docs/AGENTS.md` (full), `docs/DEPENDENCY-POLICY.md`, `docs/CODE-QUALITY.md` — read in full before
  writing anything.
- `docs/handoff/001-docker-topology.md`, `002-laravel-scaffold.md`, `003-adrs-architecture.md`,
  `004-python-browser-services.md` — the source of every "real, verified command" used in this
  pipeline, per this task's instruction not to invent them.
- `BLUEPRINT.md` §21, §22 (blueprint requirements this pipeline implements).
- `docs/reference/verified-versions.md` — Postgres/Redis image tags used in `php-tests`' `services:`
  block match this file exactly (`pgvector/pgvector:pg18-trixie`, `redis:8-trixie`).
- GitHub API (`api.github.com/repos/<owner>/<repo>/git/ref/tags/<tag>` and, for annotated tags,
  `.../git/tags/<sha>`), queried live on 2026-09-08 for every pinned action SHA — not from training
  data memory. Re-queried a second time after the file was written to catch transcription errors
  (this caught and fixed one: `actions/cache`).
- `docker/build-push-action@v7.3.0`'s own `src/context.ts` (read directly from GitHub, not assumed)
  to confirm an empty `target` input omits `--target` rather than erroring.
- `anchore/sbom-action@v0.24.2`'s `action.yml` and compiled `dist/index.cjs` (read directly) to
  confirm input names and the default-artifact-naming logic that keeps the four matrix jobs' SBOM
  artifacts from colliding.
- `aquasecurity/trivy-action@v0.36.0`'s `action.yaml` (read directly) to confirm `image-ref`,
  `scanners`, `severity`, `ignore-unfixed`, `exit-code` are current, valid inputs.
- `gitleaks dir --help` (run directly against the pinned image) for the `--gitleaks-ignore-path`
  flag and its fingerprint-matching behavior — established empirically, not from documentation.
