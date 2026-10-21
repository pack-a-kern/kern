# Dependency policy — binding

`BLUEPRINT.md` §29 + §22. Security-first. Every agent follows this without exception.

## 1. Pin everything. No floating versions.

| Ecosystem | Manifest requirement | Lockfile | Install command in CI |
|-----------|----------------------|----------|------------------------|
| PHP / Composer | Tight constraint on a **verified** minor, e.g. `"laravel/framework": "^13.23"` — never `*`, never `dev-*`, never an unbounded `>=` | `composer.lock` **committed** | `composer install --no-interaction --prefer-dist` |
| Node / npm | **Exact** versions, e.g. `"vite": "7.1.4"` — no `^`, no `~`, no `latest` | `package-lock.json` **committed** | `npm ci` (never `npm install`) |
| Python / uv | Explicit lower+upper bound, e.g. `fastapi>=0.141,<0.142` | `uv.lock` **committed** | `uv sync --frozen` |
| Docker | Exact tag **and** digest, e.g. `php:8.5.11-fpm-trixie@sha256:…` — in compose and every Dockerfile, never `latest`, never a floating `8-trixie` | the digest | `make pins` reports a newer build of a pinned tag |
| Go (edge) | The whole graph in `docker/caddy/build/go.mod` | `go.sum` **committed** | `go build -mod=readonly` |

A dependency whose version cannot be pinned does not get added.

## 2. Only versions that are both current and safe

Before adding or bumping anything:
1. Check the latest stable release. Do not add an abandoned or unmaintained package.
2. Check for known CVEs. A package with an unpatched critical CVE is not added — find an alternative.
3. Record the version and the date you checked in `docs/reference/verified-versions.md`.
4. **No alpha/beta/RC in any manifest** unless there is no stable alternative and an ADR justifies it.

## 3. Security-sensitive categories

Per §29, for anything touching **crypto, auth, or key management**:
- Use platform primitives — libsodium, PHP's own password/crypto APIs, the KMS SDK.
- **Never** hand-roll a cryptographic algorithm or protocol.
- Never adopt an abandoned package. Check the last release date and open-issue activity.

## 4. Justification is mandatory

Every new dependency is recorded in your handoff note with: what it does, why the standard library
or an existing dependency is insufficient, its license, its last release date, and its maintenance
status. §28 rule 9 forbids adding a dependency without justification.

Prefer, in order: the language/framework standard library → an existing project dependency →
a first-party Laravel/official package → a well-maintained third-party package.

### Caddy modules are dependencies too

The edge's binary is rebuilt with provider modules compiled in (`docker/caddy/Dockerfile`), from a
module graph committed whole in `docker/caddy/build/go.mod` and `go.sum` — each module pinned to an
exact version, everything under them locked by hash, recorded in
`docs/reference/verified-versions.md`, and justified the same way. A module is added only when a
question in `scripts/install.sh` selects it: compiling a provider nothing can choose is the
"for later" code `docs/CODE-QUALITY.md` forbids, and it is also one more Go dependency in a binary
that terminates TLS.

## 5. Audit gates in CI

These run on every PR and **fail** the build — they are not advisory:
`composer audit` · `npm audit --audit-level=high` · Python dependency audit ·
container image vulnerability scan · secret scan · SBOM generation.

## 6. Updates

Lockfile changes arrive by PR with CI green, never by an unpinned floating range resolving on its
own. Critical CVEs follow an emergency patch path. Monthly dependency review per §29.
