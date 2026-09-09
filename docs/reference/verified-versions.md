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
| Target PHP | **8.5** (latest tag at check: 8.5.10) | hub.docker.com/_/php |

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
| Target | **3.14** (`python:3.14-slim-trixie`) |
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

## Container images (tags confirmed present)

| Service | Image |
|---------|-------|
| app / queue / scheduler / reverb | `php:8.5-fpm-trixie` |
| postgres | `pgvector/pgvector:pg18-trixie` (Postgres 18 + pgvector 0.8.6) |
| redis | `redis:8-trixie` |
| minio | `minio/minio` (last release 2025-09-07 — see STATUS known issues) |
| openbao | `openbao/openbao:2.6.2` |
| clamav | `clamav/clamav:1.5-debian13-slim` |
| python | `python:3.14-slim-trixie` |

## Postgres extensions required

`vector` (pgvector), `unaccent` (Romanian diacritics ș/ț/ă/î/â), `pg_trgm` (fuzzy article lookup),
plus the built-in `romanian` text search configuration.

## Not yet verified — do not assume

- ANAF D212 accepted submission artifact format.
- Whether ANAF's official validator can run in CI.
- ANAF SPV portal DOM structure, field names, error codes.
- BNR XML endpoint stability (blueprint §7.1 notes 2026 communications about XML access).
