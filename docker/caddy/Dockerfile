# Kern — edge (Caddy) image.
#
# Caddy rather than nginx. The reasons are in docs/INSTALLER-PLAN.md §4; the two that decided it:
# the certificate becomes something the installer obtains and the server renews, instead of a file
# somebody generated once, and the edge stops being C code with a CVE chain this image had to purge
# package by package (docker/nginx/Dockerfile did exactly that for libgd/libheif).
#
# Exact tag plus digest per docs/DEPENDENCY-POLICY.md §1 — `make pins` reports a newer build of either.
# Alpine, because the edge needs no Debian package: the healthcheck uses busybox's own wget and nothing
# else is installed on top. Both verified 2026-10-20 — see docs/reference/verified-versions.md.

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
#
# And one more module, for the same proxy: Cloudflare's address ranges, as a source of trusted proxies.
# Behind the proxy every connection comes from Cloudflare, so the visitor's own address is only in the
# `CF-Connecting-IP` header — believable from Cloudflare and from nobody else. The module fetches the
# ranges Cloudflare publishes and refreshes them, so the list is never a copy here that ages. Used
# only when the Caddyfile's `KERN_EDGE_TRUSTED_PROXIES` names it. The module has no tagged release;
# this is its latest commit (2023-11-30, f53b62aa13cb), resolved from the Go module proxy 2026-10-20.
#
# ## Built from a committed go.mod, not `xcaddy build --with`
#
# `--with` pins the two modules it names and nothing under them: the rest of the graph — some 150
# libraries — was resolved again on every build, and this binary holds the DNS token and every TLS key.
# docker/caddy/build/ commits the whole graph with its hashes; `-mod=readonly` fails the build rather
# than resolve anything not written there, and `go mod verify` checks each download against go.sum.
# To move a version: `go get` it in that directory with this builder image, `go mod tidy`, commit both.
FROM caddy:2.11.7-builder-alpine@sha256:95a5e7e2692b5eb9545aadc5b90a39f6f509bee59c9508a493f83ee902eaef63 AS builder
WORKDIR /src
COPY docker/caddy/build/ ./
RUN go mod download \
    && go mod verify \
    && CGO_ENABLED=0 go build -mod=readonly -trimpath -ldflags '-w -s' -o /usr/bin/caddy .

FROM caddy:2.11.7-alpine@sha256:e70b164da81ef698b3ad19af799ea6086ce6d9e48ed4079eb9fe26f6fbf14ee5

# The rebuilt binary, replacing the base image's — and with it the file capability the base image's
# binary carries (`cap_net_bind_service=ep`, for binding 80 and 443). A plain `go build` sets none.
# That matters: a binary with an *effective* file capability cannot be exec'd at all under
# `cap_drop: [ALL]`, which fails as `exec /usr/bin/caddy: operation not permitted` with no further
# explanation. This one binds 8080 and 8443 and never needs it. Until the build moved off xcaddy, which
# sets the capability on purpose, a `setcap -r` below removed it.
COPY --from=builder /usr/bin/caddy /usr/bin/caddy

# A non-root user, no home, no shell. Caddy listens on the unprivileged 8080/8443 (see the
# Caddyfile's global options) specifically so no capability has to be added back after
# `cap_drop: [ALL]` in compose.yaml — the host maps 80 and 443 onto them.
#
# 10001 is also the uid the stack's CA (docker/certs) issues the edge's certificate for, and the one
# a pair the installer copies into docker/caddy/certs is made readable by. Both are mounted read-only,
# and a private key that only root can read is a key this process cannot read: the edge would start,
# fail to load TLS and take the site down with it.
#
# /data holds the TLS state — Caddy's own CA when `tls internal` is used, and ACME account keys and
# certificates when a domain is configured — so it is a named volume in compose, not a tmpfs:
# re-issuing on every restart runs into Let's Encrypt's rate limits. /config is Caddy's autosave
# directory, which stays empty here because the Caddyfile turns the admin API off.
# curl, and with it libcurl and brotli-libs, which only libcurl needs: the base image ships them and
# nothing here uses them — the healthcheck is busybox's wget. All three had HIGH/CRITICAL
# advisories in CI's Trivy scan on 2026-11-03; a package that is not there needs no fix.
RUN apk del --no-cache curl

RUN addgroup -g 10001 -S caddy \
    && adduser -u 10001 -S -G caddy -H -D -s /sbin/nologin caddy \
    && chown -R caddy:caddy /data /config

COPY docker/caddy/Caddyfile /etc/caddy/Caddyfile

# Laravel's public/ directory, built by the apps/api pipeline (composer + `npm run build`) before
# this image is built. This image does not run Vite itself — CI builds the assets first, then builds
# this image, so public/build (the Vite manifest and its hashed files) is already present when this
# COPY runs. Development mounts the live directory over it instead (compose.dev.yaml), because an
# asset rebuild otherwise leaves the edge serving hashes that no longer exist.
COPY apps/api/public /var/www/html/public

USER caddy
EXPOSE 8080 8443
