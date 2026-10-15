# STATUS — live project state

Every agent updates this file before finishing. It is the first thing the next agent reads.

**Last updated:** 2026-09-08 — **M0–M3, M5–M11 complete** (M4 blocked on human review; M12 in progress).

Verified on a dedicated Postgres database with no concurrent writers:
**729 passed / 0 failed**, identical across two consecutive runs (2152 assertions).
Pint PASS (771 files) · PHPStan level 9, no baseline: no errors · Deptrac 0 violations.
Python: 86 passed. ClamAV healthy and detecting EICAR. Laravel↔Python extraction contract
reconciled and verified end-to-end against a live container.

---

## Milestone progress

| # | Milestone | Status | Handoff notes |
|---|-----------|--------|---------------|
| M0 | Repository & platform baseline | **complete** | [001](handoff/001-docker-topology.md) [002](handoff/002-laravel-scaffold.md) [003](handoff/003-adrs-architecture.md) [004](handoff/004-python-browser-services.md) [008](handoff/008-ci-pipeline.md) |
| M1 | Security foundation | **complete** | [005](handoff/005-crypto-core.md) [006](handoff/006-audit-chain.md) [010](handoff/010-crypto-hardening.md) [011](handoff/011-identity-tenancy.md) [012](handoff/012-encrypted-object-store.md) [015](handoff/015-m1-integration-repairs.md) [017](handoff/017-authentication.md) |
| M2 | Source registry + BNR | **complete** (live endpoint resolved: `curs.bnr.ro`) | [014](handoff/014-source-registry-bnr.md) [016](handoff/016-postgres-suite-stabilisation.md) |
| M3 | Legislation ingestion | **complete** | [018](handoff/018-legislation-ingestion.md) |
| M4 | Historical PF dataset 2020–2026 | not started | — |
| M5 | Deterministic tax engine | **complete** | [020](handoff/020-tax-engine.md) |
| M6 | Wallet | **complete**; reports screen and counterparty rules reworked | [021](handoff/021-wallet.md) [047](handoff/047-reports-redesign-and-counterparty-rules.md) |
| M7 | PWA document capture | **complete** | [019](handoff/019-pdf-text-extraction.md) [022](handoff/022-document-pipeline.md) [026](handoff/026-clamav-container-fix.md) [027](handoff/027-contract-reconciliation.md) [030](handoff/030-pwa-frontend.md) |
| M8 | Rental module | **complete** | [025](handoff/025-rental-module.md) |
| M9 | D212 | **complete** (real form spec + artifact format still unknown) | [028](handoff/028-d212-declarations.md) |
| M10 | SPV automation | **scope reduced to document sync** (ADR 0012 — filing removed); endpoints, screen and playbook transport built ([048](handoff/048-browser-worker-playbooks.md), ADR 0019); the playbook's selectors await one observed session | [032](handoff/032-spv-automation.md), [048](handoff/048-browser-worker-playbooks.md) |
| M11 | RAG assistant | retrieval complete; answer generation awaits provider | [029](handoff/029-hybrid-retrieval.md) |
| M12 | PFA/SRL extension | in progress | — |

Status values: `not started` · `in progress` · `blocked` · `complete`

---

## Files currently being worked on

Claim a path here before editing it, release it when your handoff note is written.

| Path | Agent / task | Since |
|------|--------------|-------|
| `apps/api/app/Support/Crypto/**`, key migrations | 005 crypto core | wave 2 |
| `apps/api/packages/tax-romania/src/Audit/**` | 006 audit chain | wave 2 |
| `apps/processing/pyproject.toml`, `docs/adr/0008-*` | 007 PDF licence swap | wave 2 |

---

## Deployment note (2026-10-15)

The stack is exposed on a **public IP** (`https://your.server.ip/`). Hardened per
`handoff/037-public-exposure-hardening.md`: debug off, TLS on, HTTP→HTTPS redirect, all secrets
rotated.

**Installing it is one command**: `make install` ([049](handoff/049-the-installer.md)), and
`docs/DEPLOYMENT.md` is written around it. The edge is Caddy, which can obtain and renew a real
certificate itself once a domain is set. OpenBao is no longer in dev mode — it persists to a volume
and hands the application a scoped token.

Still a **dev** deployment, for two reasons rather than the four it used to be: the certificate is
self-signed until a domain is pointed here, and OpenBao's unseal key sits in plaintext inside its own
volume. `docs/DEPLOYMENT.md` §8 is the full list. No real taxpayer data belongs in it yet.

## Known issues

> All three HIGH CVEs recorded below were **fixed and re-scanned to zero** on 2026-09-08
> (handoff 009). Investigation showed two of the three original diagnoses were wrong: the
> vulnerable packages were vendored inside the base images' own `pip`/`npm`, not in our
> dependency trees. Three further undocumented HIGH CVEs were found and fixed at the same time —
> **7 HIGH → 0** across four images. The spv-browser healthcheck bug found during that work is
> fixed in handoff 013.

Bugs found outside your own scope. Record, do not silently fix.

| # | Issue | Found in | Severity |
|---|-------|----------|----------|
| P1 | **Passkey registration is broken.** `POST /auth/passkeys/registration` returns 422 *"WebAuthn registration challenge expired, was already used, or was never started"*. The challenge is written to `webauthn:creation:{userId}` by the options call and not found by the register call, in the same test, as the same user. Likely the two requests do not share a cache repository instance. Affects the real app, not just tests — the two passing passkey tests only cover rejection paths, which need no stored challenge. **Deferred by owner 2026-09-08**, to be fixed after the screens. | `packages/platform/src/Identity/Support/Webauthn/{ChallengeStore,PasskeyManager}.php` | High — a security feature that silently does not work |
| 1 | ~~`kern-python-runtime` image: `msgpack` 1.1.2 HIGH CVE (GHSA-6v7p-g79w-8964)~~ | **RESOLVED 2026-09-08** — root cause was not `pyproject.toml`/`uv.lock` at all: both `msgpack` and an undocumented second HIGH finding (`setuptools` 70.3.0, CVE-2025-47273) were vendored inside `pip`, preinstalled by the `python:3.14-slim-trixie` base image and never invoked at runtime. Fixed by removing `pip`/`ensurepip` from the `runtime` stage in `docker/python/Dockerfile`. Trivy: 2 HIGH → 0. See [009](handoff/009-cve-remediation.md). | ~~High~~ |
| 2 | ~~`kern-edge` image: `libde265-0` 1.0.15-1+deb13u1 HIGH CVE (CVE-2026-33164)~~ | **RESOLVED 2026-09-08** — `libde265-0` is pulled in by `nginx-module-image-filter` (unused: no `load_module`/`image_filter` directive anywhere in this repo's nginx config) via `libgd3 → libheif1 → libheif-plugin-libde265`. Fixed by purging all six packages in `docker/nginx/Dockerfile` rather than waiting on a Debian trixie point-release. Trivy: 1 HIGH → 0. See [009](handoff/009-cve-remediation.md). That image no longer exists: the edge is Caddy (Go) since `docker/caddy/`, where this class of dependency is not present at all. | ~~High~~ |
| 3 | ~~`kern-spv-browser` image: `brace-expansion` 5.0.7 HIGH CVE (CVE-2026-14257)~~ | **RESOLVED 2026-09-08** — `apps/spv-browser/package-lock.json` already resolved `brace-expansion` to a patched 5.0.9 as a `dev`-only dependency; the actual flagged instance (plus two undocumented siblings, `ip-address`/CVE-2026-69192 and `tar`/CVE-2026-73566) came from npm's own globally-installed CLI bundled inside the `mcr.microsoft.com/playwright:v1.63.0-noble` base image, unrelated to this app's dependency tree. Fixed `docker/playwright/Dockerfile` two ways: converted to a multi-stage build (`npm ci --omit=dev` in the runtime stage, per Open question #2 in [001](handoff/001-docker-topology.md)), and removed the base image's global `npm`/`npx` (a build-time tool the running container never uses) from the runtime stage. Trivy: 4 HIGH → 0. See [009](handoff/009-cve-remediation.md). | ~~High~~ |
| P2 | **`StatementImport` imports four of `Wallet`'s Eloquent models**, which `docs/AGENTS.md` §6 forbids: a module must reach another through DTOs, contracts, application services or events, so that it could be extracted as a package without carrying the other's database layer. `Counterparty` and `TransactionSplit` in `Services/ClassifyKnownCounterparties.php`, `FinancialTransaction` in `Actions/DeleteStatementImport.php` and `Services/StatementFingerprintReindexer.php`, `FinancialAccount` in `Actions/ApplyColumnMapping.php`. **Deptrac does not catch it**: the `StatementImport -> Wallet` direction is allowed deliberately — that module exists so Wallet and Documents need not know about each other — and deptrac checks which classes a file names, not whether they are models or services. A fifth crossing of the same kind was closed in [047](handoff/047-reports-redesign-and-counterparty-rules.md) by returning an `int` instead of an Eloquent builder. | `packages/tax-romania/src/StatementImport/{Actions,Services}` | Medium — nothing is broken; the module boundary is the thing at risk |

---

## Blocked, awaiting a human decision

| # | What is blocked | What is needed | Raised |
|---|-----------------|----------------|--------|
| 1 | M4 fiscal parameter approval | A human with Romanian fiscal competence must review and approve candidate values. No agent may set `APPROVED` (`BLUEPRINT.md` §9.3, `docs/AGENTS.md` §9). **Harder than first assessed:** Romanian law carries no machine-readable effective dates — they are prose, per-provision, with exceptions and relative references. Review is per-value date-range confirmation, not just number-checking. See `reference/legislation-effective-dates.md`. | 2026-09-07 |
| 2 | D212 output format — **lead found**: artifact is an XFA "PDF inteligent", see `reference/spv-pf-portal-observations.md` | **Narrowed 2026-09-08:** ANAF publishes *no* documented D212 submission API — verified, see `reference/anaf-spv-integration.md`. The portal artifact format still must be observed, never guessed. | 2026-09-07 |
| 3 | External AI provider | Provider, region and DPA for ADR 0005 document processing. | 2026-09-07 |
| 4 | ~~PyMuPDF AGPL licence~~ | **RESOLVED 2026-09-08** — switching to permissive pdfplumber/pypdf per owner decision. See ADR 0008. | 2026-09-08 |
| 6 | SPV portal selectors | One observed sign-in and inbox session. `tools/observe-spv-page.js` collects the structure without reading any field's value; what it prints goes into `packages/tax-romania/config/playbooks/anaf_spv.php`, whose selectors are placeholders until then (`docs/AGENTS.md` §1 rule 1). Everything around it is built. | 2026-10-06 |
| 5 | ~~BNR FX XML endpoint~~ | **RESOLVED 2026-09-08** — BNR moved the feeds to `curs.bnr.ro`; found by rendering the page with our own Playwright worker. Daily + 2005–2026 archives verified live and configured. See `reference/bnr-fx-endpoints.md`. | 2026-09-08 |
