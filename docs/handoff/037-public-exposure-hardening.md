# 037 — Public exposure hardening (coordinator)

**Milestone:** M0   **Status:** complete   **Date:** 2026-09-08

## What happened
Bringing the stack up to demo it revealed that `edge` publishes on `0.0.0.0:80` and this host has a
**public IP (your.server.ip)** with `ufw` already allowing 80/tcp and 443/tcp (leftover rules
labelled "Caddy", though no Caddy is installed). The application was therefore **live on the open
internet** in a development configuration.

## What was wrong

| # | Issue | Why it mattered |
|---|-------|-----------------|
| 1 | `APP_DEBUG=true`, publicly reachable | A Laravel debug page renders the **entire environment** — `OPENBAO_TOKEN`, `POSTGRES_PASSWORD`, `CRYPTO_BLIND_INDEX_KEYS`, `APP_KEY` — to any unauthenticated visitor who provokes an error |
| 2 | `compose.yaml` **defaulted** `APP_DEBUG` to `true` | An unsafe default is inherited by every deployment that does not override it |
| 3 | No TLS | The login form was served over plain HTTP on a public IP — passwords in clear text |
| 4 | Secrets exposed during that window | Must be treated as compromised regardless of whether anyone actually took them |

## What was done
- `APP_ENV=production`, `APP_DEBUG=false` in `apps/api/.env` and both `.env.example` files.
- **compose defaults flipped to fail-safe**: `APP_ENV:-production`, `APP_DEBUG:-false`. Debug is now
  opted *into* for local work, never inherited.
- **TLS on the edge**: nginx listens on 8443 (published as 443), TLSv1.2+1.3, plus
  `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`. Certificate is self-signed
  covering the public IP, the Tailscale IP and `localhost`. Key is `chown 101:101` (the image's
  `nginx` user) mode 640 and **gitignored**; the container still runs non-root, read-only.
- **HTTP now 301-redirects to HTTPS**, with `/healthz` deliberately left on HTTP so the container
  healthcheck needs no TLS trust.
- **All exposed secrets rotated**: `APP_KEY`, `POSTGRES_PASSWORD`, `REDIS_PASSWORD`,
  `MINIO_ROOT_PASSWORD`, `OPENBAO_DEV_ROOT_TOKEN`, `REVERB_APP_*`, `INTERNAL_SERVICE_AUTH_TOKEN`,
  `CRYPTO_BLIND_INDEX_KEYS`. Volumes were destroyed and recreated so no datastore kept an old
  credential; `openbao-init` re-created the transit KEK.

## Verified, not assumed
```
http://your.server.ip/         → 301 → https://your.server.ip/
https://your.server.ip/        → 200
http://your.server.ip/healthz  → 200   (healthcheck path, intentional)
error page secret leak count    → 0
register / login over HTTPS     → 201 / 200
```

## HSTS is deliberately NOT enabled
With a self-signed certificate, HSTS would pin browsers to an HTTPS certificate they refuse,
making the site unreachable with no easy recovery. Enable it **after** a CA-issued certificate.

## What is NOT done — this is still a dev stack
- **The certificate is self-signed**: browsers warn. Point a domain at this IP and issue via
  Let's Encrypt to remove the warning and allow HSTS.
- **OpenBao runs in dev mode** — in-memory, root token, keys lost on restart. Fine for a demo,
  never for real taxpayer data.
- **No real personal data should be entered.** `BLUEPRINT.md` §35's pre-production checklist
  (penetration test, backup restore, key rotation drill, DPA) is untouched.
