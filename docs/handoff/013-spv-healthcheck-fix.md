# 013 — spv-browser healthcheck fix

**Milestone:** M0   **Status:** complete   **Date:** 2026-09-08

## What was built
Fixed the `spv-browser` healthcheck in `compose.yaml`, reported as an out-of-scope finding in
handoff 009. It was written before `apps/spv-browser` existed and guessed both port and path.

Measured inside the real built image (`anaf-spv-browser`):

| Command | Result |
|---|---|
| `curl -f http://localhost:3000/healthz` (original) | **exit 7 — connection refused** |
| `curl -sf http://localhost:8090/healthz` | exit 22 — HTTP 404 |
| `curl -sf http://localhost:8090/health` | **exit 0** — `{"status":"ok","service":"spv-browser"}` |

The service listens on `PORT` (default **8090**, `src/server.ts`) and serves **`/health`**
(`src/routes/health.ts`). The container would therefore never have become healthy, and anything
gated on it via `depends_on: condition: service_healthy` would have stalled.

## Files changed
- `compose.yaml` — healthcheck now probes `http://127.0.0.1:8090/health` using `node -e`, matching
  the project convention of probing with the runtime's own interpreter (`python -c` for python-api).
  Also removed a stale comment claiming `apps/spv-browser` does not exist yet.

## A correction worth recording
I initially assumed the check failed because the image lacks `curl`. **That was wrong** — `curl`
*is* present, inherited from the `mcr.microsoft.com/playwright` base. The real failure was purely
the wrong port and path. `node` is still the better probe (it is the one binary this image is
guaranteed to keep, whereas curl is incidental to the base), but the reasoning in the comment was
corrected to match reality rather than leaving a plausible-sounding false claim in the file.

## How to verify
```
docker compose build spv-browser
cid=$(docker run -d --rm anaf-spv-browser); sleep 6
docker exec "$cid" node -e "require('http').get('http://127.0.0.1:8090/health',r=>process.exit(r.statusCode===200?0:1)).on('error',()=>process.exit(1))"; echo $?   # → 0
docker stop "$cid"
```

## What is NOT done
The `edge` healthcheck (`/healthz` on 8080) was checked and is **correct** — that location really
exists in `docker/nginx/conf.d/app.conf`. No change needed.
