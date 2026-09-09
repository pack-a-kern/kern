# TaxOS România — edge (nginx) image.
#
# nginx stable branch, exact tag pinned per docs/DEPENDENCY-POLICY.md.
# `1.31.x` is nginx's mainline; `1.30.x` is the stable branch, preferred here
# for a security-first edge component. Verified available 2026-09-07 via
# https://hub.docker.com/v2/repositories/library/nginx/tags — see
# docs/handoff/001-docker-topology.md "Sources consulted".
FROM nginx:1.30.4-trixie

# curl: used only by the container healthcheck to hit the self-contained
# /healthz location (does not depend on apps/api existing — see nginx.conf).
#
# The base image also ships `nginx-module-image-filter` (Debian's default
# nginx package set) and its dependency chain
# nginx-module-image-filter -> libgd3 -> libheif1 ->
# libheif-plugin-libde265 -> libde265-0, which carries a fixed HIGH CVE
# (CVE-2026-33164, fixed in 1.0.15-1+deb13u2 — not yet published for
# trixie as of this build, see docs/handoff/009-cve-remediation.md).
# docker/nginx/nginx.conf never issues `load_module` for image_filter and
# conf.d/app.conf never uses the `image_filter` directive — this repo's
# edge config has no use for HEVC/AVIF image processing at all. Removing
# the unused module and its transitive HEIF/codec dependency chain (all six
# packages explicitly named, no `apt-get upgrade`, no `--auto-remove`
# guessing) eliminates the CVE by removing the vulnerable package instead of
# waiting on a Debian security update, and shrinks the image's attack
# surface at the same time.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl=8.14.1-2+deb13u4 \
    && apt-get purge -y \
        nginx-module-image-filter \
        libgd3 \
        libheif1 \
        libheif-plugin-libde265 \
        libheif-plugin-dav1d \
        libde265-0 \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/conf.d/default.conf

COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/conf.d/app.conf /etc/nginx/conf.d/app.conf

# Laravel's public/ directory, built by the apps/api pipeline (composer +
# `npm run build`) before this image is built. This image does not run
# Vite itself — CI builds assets first, then this image is built, so
# `public/build/*` (Vite manifest + hashed assets) is already present by the
# time this COPY runs. Not verified yet: apps/api/public/build does not
# exist until that pipeline runs at least once (see "What is NOT done").
COPY apps/api/public /var/www/html/public

# Runs as the image's built-in non-root `nginx` user (uid/gid 101/101,
# verified 2026-09-07). Listens on the unprivileged port 8080 (see
# nginx.conf) specifically so no capability needs to be added back after
# cap_drop: [ALL] in compose.yaml — host port 80 maps to container 8080.
RUN mkdir -p /var/cache/nginx /var/run/nginx \
    && chown -R nginx:nginx /var/cache/nginx /var/run/nginx /var/www/html \
    && sed -i '/^user /d' /etc/nginx/nginx.conf

USER nginx
EXPOSE 8080
CMD ["nginx", "-g", "daemon off;"]
