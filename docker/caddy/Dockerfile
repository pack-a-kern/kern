# Kern — edge (Caddy) image.
#
# Caddy rather than nginx. The reasons are in docs/INSTALLER-PLAN.md §4; the two that decided it:
# the certificate becomes something the installer obtains and the server renews, instead of a file
# somebody generated once, and the edge stops being C code with a CVE chain this image had to purge
# package by package (docker/nginx/Dockerfile did exactly that for libgd/libheif).
#
# Exact tags per docs/DEPENDENCY-POLICY.md §1. Alpine, because the edge needs no Debian package: the
# healthcheck uses busybox's own wget and nothing else is installed on top.
# `2.11.7-alpine` and `2.11.7-builder-alpine` verified present 2026-10-15 via
# https://hub.docker.com/v2/repositories/library/caddy/tags — see docs/reference/verified-versions.md.

# Stage: builder — Caddy rebuilt with one DNS provider module compiled in.
#
# ## Why the official image is not enough
#
# Caddy gets a certificate by proving it controls the name. By default it proves it by answering a
# request on port 80 or a TLS handshake on 443 — which works only if the certificate authority's
# request reaches *this* server. Behind Cloudflare's proxy it does not: Cloudflare terminates the
# connection at its own edge, so the challenge never arrives and both of those methods fail, for a
# name that is otherwise configured perfectly.
#
# The answer is to prove control of the name through DNS instead: Caddy writes a record, the
# authority reads it. That needs an API token for the zone — and it needs code that can talk to that
# particular DNS provider's API, which the official image has none of. A token alone does nothing.
#
# So the binary is rebuilt with exactly one provider compiled in: Cloudflare, which is the one this
# installation's fourth TLS answer offers (`scripts/install.sh`). Adding a second provider is one
# more `--with` here and one more answer there; adding none would mean an installation behind a
# proxy cannot get a trusted certificate at all.
#
# Pinned to an exact module version like every other dependency. `v0.2.4`, released 2026-03-23,
# resolved from the Go module proxy on 2026-10-15 — see docs/reference/verified-versions.md.
FROM caddy:2.11.7-builder-alpine AS builder
RUN xcaddy build --with github.com/caddy-dns/cloudflare@v0.2.4

FROM caddy:2.11.7-alpine

# The rebuilt binary, replacing the base image's. Copied before the user is created below, because
# that step strips a file capability from this binary and so has to run after it is in place.
COPY --from=builder /usr/bin/caddy /usr/bin/caddy

# A non-root user, no home, no shell. Caddy listens on the unprivileged 8080/8443 (see the
# Caddyfile's global options) specifically so no capability has to be added back after
# `cap_drop: [ALL]` in compose.yaml — the host maps 80 and 443 onto them.
#
# 10001 is also the uid `scripts/generate-certs.sh` writes the certificate pair for. The pair is
# bind-mounted from the host read-only, and a private key that only root can read is a key this
# process cannot read: the edge would start, fail to load TLS and take the site down with it.
#
# /data holds the TLS state — Caddy's own CA when `tls internal` is used, and ACME account keys and
# certificates when a domain is configured — so it is a named volume in compose, not a tmpfs:
# re-issuing on every restart runs into Let's Encrypt's rate limits. /config is Caddy's autosave
# directory, which stays empty here because the Caddyfile turns the admin API off.
RUN addgroup -g 10001 -S caddy \
    && adduser -u 10001 -S -G caddy -H -D -s /sbin/nologin caddy \
    && chown -R caddy:caddy /data /config \
    && setcap -r /usr/bin/caddy

# Why that `setcap -r`: the base image grants the binary `cap_net_bind_service=ep` so that a
# non-root Caddy can bind ports 80 and 443. This one binds 8080 and 8443, so it never needs the
# capability — and a binary carrying an *effective* file capability cannot be exec'd at all under
# `cap_drop: [ALL]`, which fails as `exec /usr/bin/caddy: operation not permitted` with no further
# explanation. Measured, not assumed: the container would not start until this line existed.
# `libcap` is already in the base image; nothing is installed to do this.

COPY docker/caddy/Caddyfile /etc/caddy/Caddyfile

# Laravel's public/ directory, built by the apps/api pipeline (composer + `npm run build`) before
# this image is built. This image does not run Vite itself — CI builds the assets first, then builds
# this image, so public/build (the Vite manifest and its hashed files) is already present when this
# COPY runs. Development mounts the live directory over it instead (compose.dev.yaml), because an
# asset rebuild otherwise leaves the edge serving hashes that no longer exist.
COPY apps/api/public /var/www/html/public

USER caddy
EXPOSE 8080 8443
