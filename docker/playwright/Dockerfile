# Base image ships Chromium/Firefox/WebKit already installed and matched to
# a pinned Playwright driver version — using it instead of installing
# browsers ourselves avoids a second, independently-drifting version to
# track. Pinned exact tag, verified 2026-09-07 via the MCR anonymous tag
# list (mcr.microsoft.com/v2/playwright/tags/list) and a real `docker pull`
# — see docs/handoff/001-docker-topology.md "Sources consulted".
#
# apps/spv-browser does not exist yet (a parallel agent's scope). This
# Dockerfile references it as the build context will contain it once that
# work lands — see "What is NOT done" in the handoff note.
#
# The image's `playwright` npm package version must match this image tag
# exactly (v1.63.0) once apps/browser-worker/package.json exists — a mismatch
# between the Node package and the pre-installed browser binaries is a
# common, hard-to-diagnose Playwright failure mode. Record this as a
# constraint for whoever writes that package.json.
#
# ---------------------------------------------------------------------------
# Stage: build — full `npm ci` (devDependencies included: eslint, typescript,
# typescript-eslint, @types/*) so `npm run build` (tsc) can run. This stage
# is never shipped.
# ---------------------------------------------------------------------------
FROM mcr.microsoft.com/playwright:v1.63.0-noble@sha256:eff16c30e6f3f4af0a03fa4b706120d5e9b0891c344a27d64559aff5900a4a27 AS build

WORKDIR /app
COPY apps/browser-worker/package.json apps/browser-worker/package-lock.json ./
RUN npm ci
COPY apps/browser-worker/ .
RUN npm run build

# ---------------------------------------------------------------------------
# Stage: runtime — the actual spv-browser image. `npm ci --omit=dev` installs
# only `express`/`playwright` (package.json's real `dependencies`), never
# eslint/typescript/typescript-eslint or their transitive deps (e.g.
# minimatch's `brace-expansion` — CVE-2026-14257/CVE-2026-69152, both HIGH,
# see docs/handoff/009-cve-remediation.md). The single-stage build this
# replaced ran one unqualified `npm ci` directly into the shipped image,
# which is why every devDependency — and every future CVE in one — ended up
# in a container whose only job is to run `node dist/server.js`.
# ---------------------------------------------------------------------------
FROM mcr.microsoft.com/playwright:v1.63.0-noble@sha256:eff16c30e6f3f4af0a03fa4b706120d5e9b0891c344a27d64559aff5900a4a27 AS runtime

WORKDIR /app
COPY apps/browser-worker/package.json apps/browser-worker/package-lock.json ./
# `--omit=dev` alone does not clear the CVE this stage exists to fix: our own
# app's devDependencies were never the only source of it. Trivy resolves it
# to /usr/lib/node_modules/npm/node_modules/brace-expansion — npm's *own*
# globally-installed CLI, vendored inside the base image, unrelated to
# anything in apps/browser-worker/package.json (confirmed: the same finding,
# at the same path, appears in a scan of the base image before this
# Dockerfile even runs `npm ci`). The base image also ships two further HIGH
# CVEs the same way (ip-address, tar — see docs/handoff/009-cve-remediation.md).
# `npm` itself is a build-time tool; this container's only runtime command is
# `node dist/server.js`, so once `npm ci --omit=dev` has produced
# node_modules/, npm is removed rather than patched (no pip-equivalent
# "install a newer npm" step is reproducible/pinned here without vendoring a
# second Node.js distribution) — same reasoning as removing pip from
# docker/python/Dockerfile.
RUN npm ci --omit=dev \
    && rm -rf /usr/lib/node_modules/npm /usr/bin/npm /usr/bin/npx

# Ubuntu's security updates for what the pinned base image shipped before them — named, not a
# blanket `upgrade`, so the image changes only where a fix was asked for. brotli
# (CVE-2026-111497) and OpenSSL (CVE-2026-84782), found by CI's Trivy scan on 2026-11-03.
# Drop this once the base pin moves to a build that already has them.
RUN apt-get update \
    && apt-get install -y --no-install-recommends --only-upgrade libbrotli1 libssl3t64 openssl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=build /app/dist ./dist

# The base image's pre-created non-root account, used for exactly this
# purpose in Microsoft's own CI recipes — Chromium's own sandbox still needs
# either CAP_SYS_ADMIN or the `--no-sandbox` launch argument when the
# container itself already drops all other capabilities (see
# compose.yaml cap_drop/security_opt and the handoff's threat-model note).
# apps/spv-browser's Chromium launch code MUST pass `--no-sandbox` — this
# repo does not grant CAP_SYS_ADMIN.
USER pwuser

EXPOSE 3000
CMD ["node", "dist/server.js"]

# ---------------------------------------------------------------------------
# Stage: test — dev dependencies and the sources, for `make check-browser`.
#
# The browser tests drive a real Chromium, which lives in this base image and
# nowhere on a developer's host. Running them here rather than locally is the
# same arrangement `docker/python/Dockerfile`'s own `test` stage makes, and for
# the same reason: the thing under test needs the runtime the image provides.
#
# Never used as a runtime image.
# ---------------------------------------------------------------------------
FROM mcr.microsoft.com/playwright:v1.63.0-noble@sha256:eff16c30e6f3f4af0a03fa4b706120d5e9b0891c344a27d64559aff5900a4a27 AS test

WORKDIR /app

COPY apps/browser-worker/package.json apps/browser-worker/package-lock.json ./
RUN npm ci

COPY apps/browser-worker/ .

CMD ["npm", "test"]
