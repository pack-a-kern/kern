# 010 — Crypto core hardening (coordinator review of handoff 005)

**Milestone:** M1   **Status:** complete   **Date:** 2026-09-08

## What was built
Two defects found while independently verifying handoff 005 (the crypto core). Both were invisible
to the original agent because its verification container happened to have `ext-intl` installed.

### 1. Blind-index normalization degraded silently — now fails loudly
`BlindIndex\Normalizer::foldDiacritics()` returned the **unfolded** value when the ICU
transliterator was unavailable. Its own docblock states its purpose is to stop
`"Ștefan" / "Stefan" / "ştefan"` producing three different blind indexes — so the fallback caused
precisely the bug the function exists to prevent.

Why this is a security defect, not a cosmetic one: a value indexed on a node with `ext-intl` and
looked up on a node without it produces **different HMACs**, so the exact-match lookup silently
misses. That breaks duplicate-CNP detection and login-by-email, and it fails as *"no such record"*
rather than as an error — the kind of failure nobody notices. **A blind index that is wrong is
worse than one that is unavailable**, so it now throws.

### 2. `composer.json` declared no PHP extensions
The code requires `sodium` (envelope cipher), `intl` (blind index), `pdo_pgsql`, `bcmath`,
`pcntl` (Horizon) and `redis`, none of which were declared. Nothing would have caught a runtime
missing one until it failed in production. Now declared in `require`, with `config.platform`
describing the target image so resolution is identical on any host.

## Files changed
- `apps/api/app/Support/Crypto/BlindIndex/Normalizer.php` — throw instead of degrade
- `apps/api/composer.json` — six `ext-*` requirements + matching platform entries
- `apps/api/composer.lock` — hash refresh only

## How to verify
Run in `php:8.5-cli-trixie` with `intl` added (host PHP 8.3 cannot run this tree):
```
vendor/bin/pest                 → 90 passed, 14 skipped (149 assertions)
php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G  → No errors (level 9)
vendor/bin/pint --test          → PASS, 82 files
vendor/bin/deptrac analyse      → 0 violations
composer validate --strict      → valid
```
Verified that the lock refresh changed **no package versions** — only the content hash.

## Key decisions
- **Fail loudly over degrade gracefully** in any security-correctness path. Graceful degradation is
  right for availability concerns; it is wrong when the degraded output is silently *incorrect*.
- `config.platform` marks extensions present so `composer install` behaves identically everywhere;
  CI still runs `composer check-platform-reqs` against the real image for a genuine check.

## Resolved: the "flaky" Normalizer test reported in handoff 006
Not flaky. Re-ran the Normalizer suite **12 times consecutively: 12/12 pass**. The original failure
was a container without `ext-intl` — the same failure I hit on my first run. Fix #1 turns that
confusing `Class "Transliterator" not found` into an explicit message, and fix #2 prevents the
environment from being wrong in the first place.

## What is NOT done
- `ext-*` are marked present via `config.platform`; that is a resolution convenience, not proof.
  The real check is `composer check-platform-reqs` in CI against the production image.

## Sources consulted
`docs/handoff/005-crypto-core.md`, `docs/handoff/006-audit-chain.md`, `BLUEPRINT.md` §4.2/§4.4,
`docs/AGENTS.md` §8, `docs/DEPENDENCY-POLICY.md`.
