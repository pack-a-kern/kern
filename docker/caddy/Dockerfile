# Kern — edge (Caddy) image.
#
# Caddy rather than nginx. The reasons are in docs/INSTALLER-PLAN.md §4; the two that decided it:
# the certificate becomes something the installer obtains and the server renews, instead of a file
# somebody generated once, and the edge stops being C code with a CVE chain this image had to purge
# package by package (docker/nginx/Dockerfile did exactly that for libgd/libheif).
#
# Exact tag per docs/DEPENDENCY-POLICY.md §1. Alpine, because the edge needs no Debian package: the
# healthcheck uses busybox's own wget and nothing else is installed on top.
# `2.11.7-alpine` verified present 2026-10-15 via
# https://hub.docker.com/v2/repositories/library/caddy/tags — see docs/reference/verified-versions.md.
FROM caddy:2.11.7-alpine

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
