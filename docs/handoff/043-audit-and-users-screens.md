# 043 — Reverb wiring, audit log, users & roles

## Reverb was built but unreachable

Events, channels, `laravel-echo`, and two subscribing pages all existed. The last hop did not:

1. `edge` and `reverb` shared no network. `reverb` now joins `edge_net`.
2. nginx had no WebSocket location. Added `location /app/` → `reverb:8080` with the Upgrade
   headers and a 3600s read timeout. The server-side publish leg (`/apps/{id}/events`) is
   deliberately **not** proxied — nothing outside should be able to broadcast.
3. `docker/nginx/Dockerfile` COPYs `app.conf`, so the edit did nothing until the image was
   rebuilt — the block was in the repo and absent from the running server, and the proxy appeared
   to 404. `compose.override.yaml` now mounts it in dev, matching what was already done for
   `public/`.
4. `VITE_REVERB_APP_KEY` was `"${REVERB_APP_KEY}"`. **Vite does not expand `${...}`**, so the
   client shipped an empty key. Worse, the two `.env` files held *different* keys and compose
   passes the **root** one to the container. Now a literal, matching the running server.
5. `VITE_REVERB_HOST=localhost` was baked in at build time, so every browser that was not on the
   server itself failed. `lib/echo.ts` now defaults to `window.location.hostname` and the page's
   own scheme; the env vars remain as an override.

Verified: `GET /app/{key}` with Upgrade headers over HTTP/1.1 returns **101 Switching Protocols**
through nginx over TLS.

## Audit log — `admin/setari`'s sibling at `/admin/audit`

`GET /api/v1/audit/events` (keyset paging on `chain_key, sequence`) and `/api/v1/audit/summary`.

What the screen shows is decided by §4.8, not by taste:

- **No payload.** The table stores `payload_hash`. The trail proves a change happened and that the
  record of it is untampered, without becoming a second unencrypted copy of what it describes.
- **No IP, no severity.** Neither is modelled. Classifying event types into severities in the UI
  would write a judgement into an evidentiary record that the record does not make.
- **Integrity is re-verified per request** by walking each chain and recomputing every hash, capped
  at 25 chains. A tamper-evidence indicator that reads a stored flag is evidence of nothing.

Keyset, not `OFFSET`: the log is append-only and grows while it is read.

## Users & roles — `/admin/utilizatori`

`GET /api/v1/admin/users` returns accounts (email decrypted, capped at 200), MFA posture,
memberships, role counts, and an `enforcement` block.

**The permission matrix in the mockup was not built, deliberately.** `MembershipRole` is written on
every membership and *never consulted* — `grep MembershipRole::` finds only writes. Rendering a
matrix of ticks would have been decoration presented as fact. The screen instead reports role
counts, states plainly that no permission difference is enforced, and shows what *is*: the
settings-admin allowlist (ADR 0013 — env-level, invisible in any table, which is precisely why it
belongs on this screen) and per-subject tenancy/channel checks.

`AdminUserDirectoryTest` asserts `roles_enforced === false`. When role checks land, that test fails
and whoever implements them must update the flag and the copy — the screen cannot drift into
lying.

Only **confirmed** MFA methods count: an unconfirmed row is an abandoned setup, and counting it
would overstate the installation's security posture.

## Boundary caught by tooling

Deptrac rejected `Identity\Queries\AdminUserDirectory` depending on `Subjects\Enums\MembershipRole`.
Role counting moved to `Subjects\Queries\MembershipRoleCounts`, which is where it belonged.

## Notes for the next screen

- `Feature/Domain/Audit` is outside `RefreshDatabase` (the concurrency test forks and needs
  committed rows). An API test in that directory must `uses(RefreshDatabase::class)` per file.
- Appending to a chain needs Postgres (`SELECT … FOR UPDATE`); gate such tests on the driver.
- `UserMfaMethod`'s encrypted cast derives its AAD from `user_id`, which a relation `create()` sets
  only *after* the attribute is assigned. Insert through the query builder in tests that do not
  care about the crypto path.
- `config/sources.php`: `MF` (mfinante.gov.ro) is registered and allowlisted but referenced by no
  code. Either wire it or drop it — a dead allowlist entry is a standing question.
