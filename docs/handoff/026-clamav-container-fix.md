# 026 — ClamAV container fix (coordinator)

**Milestone:** M0/M7   **Status:** complete   **Date:** 2026-09-08

## Problem
`docs/handoff/022-document-pipeline.md` reported that the `clamav` service crash-loops. Reproduced:
**11 restarts, `health=unhealthy`**, never serving. Since ClamAV is the gate every uploaded document
must pass (§14.1), the document pipeline could not run against the real compose stack at all.

Three distinct hardening settings each blocked a different part of the image's own start-up. Each
was only visible after fixing the previous one.

| # | Symptom | Cause | Fix |
|---|---------|-------|-----|
| 1 | `chown: cannot read directory '/var/lib/clamav': Permission denied` | `cap_drop: ALL` removed the caps the entrypoint needs to chown its data dir and drop to the `clamav` user | `cap_add: CHOWN, DAC_OVERRIDE, FOWNER, SETUID, SETGID` — the same minimal pattern already granted to postgres/redis |
| 2 | `ln: failed to create symbolic link '/var/lock/lock': Read-only file system` | `read_only: true` with no writable `/var/lock` | `tmpfs: /var/lock` |
| 3 | `Failed to open log file /var/log/clamav/freshclam.log` → `Initialization error!` | freshclam refuses to initialise without a writable log path | `tmpfs: /var/log/clamav` |

## Result — verified, not assumed
```
status=running  health=healthy  restarts=0
clamd: "socket found, clamd started."

EICAR test string  → Infected files: 1, exit=1   (detects)
clean file         → exit=0                       (passes)
```

The container keeps **`read_only: true`, `cap_drop: ALL`** and non-root operation. Only three
tmpfs mounts and five capabilities were added — no hardening was traded away to make it boot.

## Key decision
Each failure was fixed with the **narrowest** possible grant rather than relaxing `read_only` or
restoring capabilities wholesale. That is why it took three iterations: each fix revealed the next
requirement. Loosening `read_only: true` would have "fixed" all three at once and quietly discarded
a §3.4 control on the one service that handles hostile input by design.

## Files changed
- `compose.yaml` — the `clamav` service's `cap_add` and `tmpfs` lists, each with a comment naming
  the exact error it prevents so nobody removes them as unexplained clutter.

## What is NOT done
The document pipeline's ClamAV tests were verified by their author against a capability-patched
standalone container; they should now be re-run against the real compose service.
