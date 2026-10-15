# 049 — One command installs this; the deployment guide is written around it

**Milestone:** M12   **Status:** complete, except the VPN container (§5 of the plan), which is
blocked on a credential only the operator can supply
**Date:** 2026-10-15
**Plan:** [`../INSTALLER-PLAN.md`](../INSTALLER-PLAN.md) — items 1–6 and 8 of §7 are done

## What was built

**`scripts/install.sh` (`make install`).** Nine questions, then every step `docs/DEPLOYMENT.md` used
to ask a person to do by hand: nine secrets from `openssl rand`, `apps/api/.env` at mode 0600, the
`COMPOSE_FILE` line, the TLS pair, the frontend assets, the images, the stack, the database wait, the
migrations, the role catalogue, the first administrator, and a health check that fails the install.
`--repair` re-runs everything after the environment file and refuses to touch it.

**`kern:health`** (`packages/platform/src/Console/Commands/Settings/CheckSystemHealthCommand.php`).
`ProbeSystemHealth` on a terminal. It exists because that service was only reachable through a route
requiring a signed-in administrator, and the installer runs before any account exists. `degraded`
exits non-zero with the component named; `not_configured` passes, because it names a service
`COMPOSE_FILE` was told to leave out.

**`scripts/host-addresses.sh`.** The addresses this host answers on. Two callers need the same list
and must not disagree: the certificate's SAN list and the installer's default answer.

**`docs/DEPLOYMENT.md`, rewritten.** `make install` is section 1; the manual steps are section 6.

## What this corrected

The old guide described a stack that no longer exists. Each of these was wrong in a way an operator
would have acted on:

- three `minio*` containers and `openbao-init` listed as services — none of them exist;
- OpenBao described as running `server -dev`, with "every restart loses the key" as the headline
  risk. It persists to a volume and issues a scoped token; the remaining risk is the unseal key
  sitting in plaintext *inside* that volume, which is a different decision with a different answer;
- two Horizon supervisors where there are three (the legislation queue was missing), and a 600s AI
  timeout where the value is 1500s and env-driven;
- `REVERB_HOST` described as "your real public domain" — it is where the application *publishes*,
  internally, and setting it to a public address breaks broadcasting. The browser's address is the
  three `VITE_REVERB_*` variables, and empty is correct for every way the site is reached;
- "the bundled MinIO's defaults work as shipped" — nothing is bundled, and **nothing creates the two
  buckets**, which an installer cannot do for an endpoint it was only given credentials to.

## The one real gap

**There is no "install object storage here".** This repository ships no object-storage service, so a
standalone server with no S3 anywhere cannot be installed: documents have nowhere to go. Building a
MinIO service to answer the question was the obvious move and the wrong one — nothing has asked for
it, and `compose.services/minio.yaml` was in the plan's file list and never written because this
deployment has always used a remote one. Recorded in the plan §6 and in `DEPLOYMENT.md` §8.3.

## Cloudflare DNS-01, added the same day

Left out of the first installer because §8 requires that path to be issued once against a real zone
before it ships, and there is no zone here. Then built anyway, because everything *except* the
issuance can be proved — and because an installation behind Cloudflare otherwise has no way to get a
trusted certificate at all.

`docker/caddy/Dockerfile` is two stages now: `xcaddy build --with
github.com/caddy-dns/cloudflare@v0.2.4` on `caddy:2.11.7-builder-alpine`, then the binary copied
into the runtime image. 167 MB → 235 MB. The token and the code that can use it are two different
things: the official image has neither, and giving today's edge a token would do nothing.

The Caddyfile turn-on is one line, and its shape is the interesting part. A Caddyfile has no
conditionals and an `acme_dns` line with no provider is a configuration error, so "off" cannot be an
empty provider name — the **whole directive** is an environment variable instead, defaulting to
nothing, with the token in a second variable that Caddy reads at runtime rather than it living in
the configuration's text.

Verified: the image builds, `caddy list-modules` reports `dns.providers.cloudflare`, and
`caddy validate` passes with nothing set, with a domain and an email, and with the DNS challenge on
— where the Cloudflare module itself rejected a malformed token with its own error message, which is
what proves it is reached rather than parsed past. The **old** image also still validates the new
Caddyfile with the variable unset, which is what matters for every installation that does not use
this path.

Not verified: issuance against a real zone. The fifth TLS answer stays beside it for exactly that
reason — a Cloudflare Origin certificate is fifteen years, no token, no renewal, no module.

## Threat-model note (required by `docs/AGENTS.md` §1 rule 20)

**No secret is printed, and none is passed as an argument.** Arguments are visible in `ps`, in shell
history and in `docker inspect`. Generated values go from `openssl rand` straight into
`apps/api/.env` at mode 0600. The administrator's password is read with terminal echo off, held in a
shell variable for the length of the run, and reaches artisan on standard input — which is what
`kern:admin:create --stdin` exists for. It is cleared as soon as the command returns.

**`kern:health` prints no address.** A failing row carries the exception's class name, nothing else:
a driver's message can contain a DSN, a bucket name or a host (`BLUEPRINT.md` §4.10). The cause goes
to the log.

**An external key vault is not trusted on the operator's word.** Its token is asked for, never
generated, and whether it can actually read the transit key is proved by the `key_management` row at
the end — which fails the install. A wrong token here would otherwise surface weeks later as
unreadable taxpayer data.

## How to verify

```bash
make install                      # must refuse: apps/api/.env exists
make install ARGS=--repair        # start, wait, migrate, sync, health — safe on a live stack
docker compose exec app php artisan kern:health
```

The fresh path cannot be run against this repository, because it refuses to overwrite the
environment file. It was verified against a copy of the tree in a scratch directory, three times —
the IP answer, every service external, and everything skippable skipped — reading back the `.env`
each run wrote. The run stops at `npm ci` there, which is after the environment file and the
certificate, so both are inspectable.

```bash
docker compose exec -T app sh -c 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: vendor/bin/pest tests/Feature/Platform/Settings/CheckSystemHealthCommandTest.php'
```

Four tests. The all-healthy case needs a clamd that answers `zPING` with `PONG`, which no socket in
the test's own process can do — the probe blocks in `fread` waiting for it — so that one starts a
child process to be the stub.

## What is next

**The VPN container (plan §5, rewritten this session).** Every container currently inherits the
host's VPN, including the browser worker, which is the widest hole in the topology. The design is a
forwarder rather than a route, for the reason that section now gives, and Tailscale and WireGuard are
both first-class — they differ in two places only. It needs one credential from the operator: an auth
key or a `wg0.conf`. Without it the tunnel cannot be started, so the forwarding cannot be proved, and
§8 forbids promising it.
