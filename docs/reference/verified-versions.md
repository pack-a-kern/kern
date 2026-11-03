# Verified external facts

Every entry here was checked against the upstream source on the date shown.
**Do not add an unverified fact to this file.** If you check something, add it with its URL and date.

Checked: **2026-09-07**

## Laravel / PHP

| Fact | Value | Source |
|------|-------|--------|
| Laravel 13 released | 2026-03-17 | laravel.com/docs/13.x/releases |
| Laravel 13 PHP range | **8.3 – 8.5** | same |
| Laravel 13 bug fixes until | Q3 2027 | same |
| Laravel 13 security until | 2028-03-17 | same |
| PHP 8.5 released | 2025-11-20, active support to 2027-12-31 | php.net/supported-versions.php |
| Target PHP | **8.5** (pinned tag: 8.5.12) | hub.docker.com/_/php |

> **Security bump 2026-10-22:** pinned `8.5.11` → `8.5.12` for CVE-2026-109618 / CVE-2026-109619,
> critical unauthenticated RCE in PHP core (CVSS v4 9.2), patched in 8.5.12 / 8.4.27 / 8.3.36 /
> 8.2.35 on 2026-10-21. All PHP ≤ 8.5.11 is affected; this image serves via php-fpm, not the
> higher-risk mod_php. Digest updated on both `FROM` stages in `docker/php/Dockerfile`.

`php:8.5-fpm-trixie` builds `sodium` (shared, enabled) and compiles `--with-password-argon2`.
**Therefore ADR 0002's XChaCha20-Poly1305 and Argon2id need no custom PHP build** — only
`pdo_pgsql`, `redis`, `bcmath`, `intl` must be added.

## Composer packages (Laravel 13 compatible)

| Package | Version | Notes |
|---------|---------|-------|
| `laravel/reverb` | 1.11.1 | `^13.0` |
| `laravel/horizon` | 5.48.3 | `^13.0` |
| `larastan/larastan` | 3.11.0 | `^13` |
| `pestphp/pest` | 5.1.4 | **requires PHP ^8.4** |
| `pestphp/pest-plugin-laravel` | 5.0.1 | requires `laravel/framework ^13.23.0` |
| `laravel/pint` | 1.31.0 | |
| `deptrac/deptrac` | 4.7.1 | maintained. **`qossmic/deptrac-shim` is dead (2022) — do not use** |
| `laragear/webauthn` | ~~5.0.2~~ | **Rejected 2026-09-08**: `composer require` flags it abandoned ("Package laragear/webauthn is abandoned, you should avoid using it. Use laravel/passkeys instead."), violating `docs/DEPENDENCY-POLICY.md` §3 ("never adopt an abandoned package"). Not used. |
| `web-auth/webauthn-lib` | 5.3.8 | Checked 2026-09-08. MIT, actively maintained (releases through 5.4.x-dev), PHP `>=8.2`. Used directly (no Laravel wrapper) — see `docs/handoff/017-authentication.md` "Key decisions" for why `laravel/passkeys` (v0.2.1, pre-1.0, official but very new) was also passed over in favour of integrating the underlying, mature library directly. |
| `spomky-labs/otphp` | 11.5.0 | TOTP. Not abandoned, checked 2026-09-08. |
| `league/flysystem-aws-s3-v3` | 3.35.3 | |

> **Host gotcha:** this machine's CLI PHP is 8.3, but Pest 5 needs ^8.4. Pin the target runtime in
> `composer.json` so resolution matches the container, not the host:
> `"config": { "platform": { "php": "8.5.10" } }`

## Python

| Fact | Value |
|------|-------|
| Target | **3.14** (`python:3.14.8-slim-trixie`) |
| pypdf 6.18.0 — **license: BSD-3-Clause** (checked 2026-09-08, from the wheel's own `METADATA`: `License-Expression: BSD-3-Clause`, and PyPI JSON `info.license_expression`) | pure-Python wheel, explicit `Programming Language :: Python :: 3.14` classifier ✓ — no ABI/wheel risk at all |
| FastAPI 0.141.1 / Pydantic 2.13.5 / qdrant-client 1.19 | 3.14 ✓ |
| opencv-python-headless 5.0.0.93 | abi3 (cp37+) ✓ |
| **paddlepaddle** | **no cp314 wheels in ANY 3.x release** (3.1.1–3.3.1 = cp39–cp313 only) |

PaddleOCR is therefore **not used** — see ADR 0005. This is why Python 3.14 is viable.

`pypdf` replaced PyMuPDF (**AGPL-3.0-or-later** — copyleft, not permissive; incompatible with
`BLUEPRINT.md` §29) on 2026-09-08 for `apps/processing`'s native-PDF-text-extraction dependency — see
ADR 0008. `pdfplumber` 0.11.10 (MIT, checked 2026-09-08) was evaluated as an alternative and
rejected: it pulls in three required dependencies (`pdfminer.six`, `Pillow`, `pypdfium2`) for
layout/table capability `apps/processing` does not need, where `pypdf` alone (zero required
runtime dependencies on Python ≥3.11) covers the actual requirement — detect a page's text layer
and extract it (§14.3).

## Caddy modules compiled into the edge

The whole module graph — these two, Caddy v2.11.7 and everything under them — is locked in
`docker/caddy/build/go.mod` and `go.sum` (2026-10-20), and the build refuses to resolve anything not
written there. Before that, `xcaddy build --with` pinned these two and re-resolved the rest on every build.

| Module | Version |
|--------|---------|
| `github.com/caddy-dns/cloudflare` | `v0.2.4` (released 2026-03-23; resolved from the Go module proxy 2026-10-15, latest of five tags). Apache-2.0. Compiled in from `docker/caddy/build/go.mod`, and confirmed present as `dns.providers.cloudflare` in `caddy list-modules` on the built image. It is what lets an installation behind Cloudflare's proxy prove its name through DNS, which is the only ACME challenge that reaches a server the proxy answers for. |
| `github.com/WeidiDeng/caddy-cloudflare-ip` | `v0.0.0-20231130002422-f53b62aa13cb` — no tagged release; its latest commit (2023-11-30), resolved from the Go module proxy 2026-10-20. Apache-2.0. Compiled in from `docker/caddy/build/go.mod` and confirmed present as `http.ip_sources.cloudflare` in `caddy list-modules` on the built image. Supplies Cloudflare's published address ranges as trusted proxies, refreshed at runtime, for an installation behind Cloudflare's proxy (`KERN_EDGE_TRUSTED_PROXIES`). Untagged and quiet since 2023: worth replacing with a maintained source if one appears. |

## Container images (tag and digest)

Every image is pinned by exact tag **and** digest, in compose and in every Dockerfile — a tag is rebuilt
upstream with new OS patches under the same name, and only a digest names one build. `make pins` asks
each registry whether the tag now points at a newer build; moving a pin is a deliberate change, checked
like a version bump (§2 of DEPENDENCY-POLICY.md). All rows checked 2026-10-20.

| Service | Image | Notes |
|---------|-------|-------|
| edge | `caddy:2.11.7-alpine@sha256:e70b164d…`, built in `caddy:2.11.7-builder-alpine@sha256:95a5e7e2…` (Go 1.27.3) | 2.11.7 latest (released 2026-10-06) |
| app / queue / scheduler / reverb | `php:8.5.12-fpm-trixie@sha256:c34bcf43…`, `composer:2.10.3@sha256:2cf27542…` | 8.5.12 latest 8.5; 8.6 is RC only |
| postgres | `pgvector/pgvector:0.8.7-pg18-trixie@sha256:5895949d…` (Postgres 18.6, pgvector 0.8.7, rebuilt upstream; moved 2026-11-03) | Moved 2026-10-21 from 0.8.6: an IVFFlat build overflow (this schema indexes with HNSW) and `avg` over no rows. The extension's objects follow on the next `make migrate`, which runs `kern:db:update-extensions` |
| redis | `redis:8.10.2-trixie@sha256:97ec8815…` | from 8.10.1: 8.10.2 (2026-10-07) is a security release. Same tag rebuilt upstream, moved 2026-11-03 |
| openbao | `openbao/openbao:2.6.4@sha256:cf2340fc…` | from 2.6.2: 2.6.3 and 2.6.4 (2026-10-01) carry security fixes. 2.7.1 exists — a minor step, later. Tried first on a copy of the data volume (unseal, KEK, encrypt/decrypt) |
| clamav | `clamav/clamav:1.5.4-debian13-slim@sha256:c21aa18c…` | 1.5.4 rebuilt upstream with Debian patches, moved 2026-11-03 |
| python | `python:3.14.8-slim-trixie@sha256:fbecd461…`, `ghcr.io/astral-sh/uv:0.12.24@sha256:3af4716e…` | 3.14.8 latest 3.14; 3.15.0 (2026-10-17) too new. uv from 0.12.10, same series (0.13 exists) |
| browser-worker | `mcr.microsoft.com/playwright:v1.63.0-noble@sha256:eff16c30…` | 1.64.1 exists — needs the npm package moved with it and the SPV flow retested; its own step |
| certs | `alpine:3.24.2@sha256:294b683c…` (released 2026-09-18), plus Alpine's `openssl` — 3.5.9 at the check | the stack's own CA (`docker/certs`) |
| mailpit (dev) | `axllent/mailpit:v1.31.6@sha256:23fe39e8…` | from v1.31.1: v1.31.2 fixed GHSA-2vgv-6hcp-mf43 |
| minio | `minio/minio` (last release 2025-09-07 — see STATUS known issues) | **not in any compose file**: this deployment points at a MinIO it does not run |

The full digests are the ones in the files; the short forms here are for reading.

## Postgres extensions required

`vector` (pgvector), `unaccent` (Romanian diacritics ș/ț/ă/î/â), `pg_trgm` (fuzzy article lookup),
plus the built-in `romanian` text search configuration.

## Not yet verified — do not assume

- ANAF D212 accepted submission artifact format.
- Whether ANAF's official validator can run in CI.
- ANAF SPV portal DOM structure, field names, error codes.
- BNR XML endpoint stability (blueprint §7.1 notes 2026 communications about XML access).
