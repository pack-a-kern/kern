# 051 — A server installs a release without a clone

## What was built

A release is now six images: the five the stack runs, and `kern-install`, which holds only the files
that run them. A server signs in to the registry, checks `kern-install`'s signature once by hand,
copies its files out and runs `make install`. No git, no npm, no source code, and one token
(`read:packages`) for everything. `make update VERSION=…` fetches the next release's files the same
way, checked by the verifier the current release brought.

## Files created / changed

- `docker/install/Dockerfile` — new. `FROM scratch`, one folder `/kern`: the compose files without
  their `.dev` overlays, every file compose mounts from the host, the installer, updater and verifier,
  the `.env` templates, the server Makefile, and `RELEASE` (version, registry, signer — from build args).
- `docker/install/Makefile` — new. The server targets (install, update, up, down, logs, shell, migrate,
  reload, smoke, tls-ca), moved out of the root Makefile, which now includes it. Shipped as the release's
  `Makefile`, so a server has no target that needs the source.
- `scripts/update.sh` — new. The update, out of the Makefile: fetch and check `kern-install`, rename
  each file into place, `exec` the new release's own copy for the images, migrations and restart.
- `scripts/tests/release-bundle.sh` — new. Extracts the image as a server does and fails on a missing
  bind-mount source, a missing called script, an incomplete `RELEASE`, or anything that must not ship
  (a `.dev` file, a key or certificate, an environment file, source code).
- `scripts/install.sh` — release mode is "there is a `RELEASE` file", not "`KERN_IMAGES` is set and
  the checkout is on a tag". A clone builds.
- `scripts/verify-images.sh` — checks the images named on its command line, when there are any.
- `.github/workflows/ci.yml` — `install` in the containers matrix; a "Release values" step computing
  the registry, version and build args once (the push step uses it too); "Check the release bundle".
- README, `docs/DEPLOYMENT.md`, `apps/api/.env.example` — the release install is the bundle.

## Key decisions

- **The first signature check is by hand.** A bundle could carry a lying verifier, so the one check
  that has no trusted verifier on the server yet is the README's cosign command, run before a file is
  copied out. Every later one is done by the previous release's verifier.
- **`FROM scratch`, copied out, never run.** Fetching the files executes nothing from them.
- **Copied by digest, renamed into place.** The digest that was verified is the one copied from; each
  file is `mv`'d, so the running updater keeps reading the copy it started with.
- **The clone's pull mode is gone.** One way to run a release, not two; a clone builds its own images.
- **The list of files cannot drift.** CI resolves every bind mount in the bundle's own compose files.

## How to verify

```bash
docker build -f docker/install/Dockerfile --build-arg KERN_VERSION=0.0.0-dev \
  --build-arg KERN_IMAGES=ghcr.io/test --build-arg KERN_IMAGES_SIGNER=test/kern -t kern-install:ci .
scripts/tests/release-bundle.sh kern-install:ci
make test-installer
```

Tested by hand: a bundle missing `clamd.conf` and carrying `docker/caddy/certs/kern.key` fails the
check on both; `scripts/update.sh` against an unsigned release changed nothing; with the check passing
it replaced the release's files, left `apps/api/.env` alone, removed its staging folder and handed over
to the new copy.

## What is NOT done

- Files a later release deletes stay on the server; they are not read once the compose files stop
  naming them.
- The web installer discussed alongside this.

## Threat model

- **A replaced bundle in the registry:** refused by the signature check — by hand on first install,
  by the current release's verifier on update — before any file is copied.
- **Secrets:** the bundle ships none; `apps/api/.env`, `docker/caddy/certs/` and `docker/tls/trusted/`
  are never overwritten (the bundle holds those folders empty), and CI fails if a key or `.env` is in it.
