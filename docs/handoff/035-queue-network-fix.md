# 035 — Queue worker could not reach ClamAV or python-api (coordinator)

**Milestone:** M0/M7   **Status:** complete   **Date:** 2026-09-08

## The defect
`docs/handoff/033-pwa-wiring.md` reported that the `queue` service is not attached to
`processing_net`. Confirmed:

```
app     : edge_net, app_net, processing_net, browser_net, data_net
queue   : app_net, data_net          ← missing processing_net
clamav  : processing_net
python-api : processing_net
```

**Why this matters more than it looks.** Both document-pipeline jobs run on the *queue worker*, not
in `app`:

- `Documents\Jobs\ScanQuarantinedDocument` → needs `clamav:3310`
- `Documents\Jobs\ProcessScannedDocument` → needs `python-api:8000`

So every queued document would fail its antivirus scan and its text extraction in the real stack —
while the identical code succeeds when exercised synchronously from `app`, which *does* have the
network. That asymmetry is exactly what makes this dangerous: it passes in testing and fails in
production, on the antivirus gate.

## The fix
`processing_net` added to `queue` in `compose.yaml`, with a comment naming both jobs so nobody
removes it as unexplained clutter.

`browser_net` was deliberately **not** added: no SPV job implements `ShouldQueue`, so the queue has
no reason to reach `spv-browser`. Grant the narrowest network that works.

## Verified, not assumed
```
docker compose run --rm --no-deps --entrypoint php queue -r '...fsockopen...'
  clamav:3310      reachable ✓
  python-api:8000  reachable ✓
```
(An earlier attempt using `/dev/tcp` reported both unreachable — a false negative: `/dev/tcp` is a
bash-ism and the image's `sh` is dash. The failure was in the test, not the fix.)

## The second reported bug — NOT reproducible
Handoff 033 also reported a Postgres migration-ordering defect in `source_sections`' self-referencing
FK breaking `migrate:fresh` on a new database. **I could not reproduce it.** On a genuinely fresh
database (`CREATE DATABASE` + the three extensions), `php artisan migrate --force` exits **0** with
every migration `DONE`, including the newest.

The likely explanation is the §7b hazard: it was observed while another agent was mid-migration
against the shared development database. Recorded here rather than silently dropped — if it
reappears on a clean database, this note is the starting point.
