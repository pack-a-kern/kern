# The release's files for a server: everything that runs the published images, and nothing that builds
# them. No source code, no shell, no program — `FROM scratch` holding one folder, /kern, which a server
# copies out with `docker create` + `docker cp` and never runs. See docs/DEPLOYMENT.md "From a release".
#
# Built and signed by CI with the other images, as ghcr.io/<owner>/kern-install:<version>. A server
# checks that signature before it copies a single file out (first install: by hand, as the docs show;
# every later one: scripts/update.sh, with the verifier the previous release brought).
#
# What a server needs is what compose mounts from the host and what the installer reads. CI extracts
# this image and fails if any path compose mounts is missing (scripts/tests/release-bundle.sh), so this
# list cannot fall behind the compose files.

FROM alpine:3.24.2@sha256:294b683cb724975bec92580e1e685676bd4b50bda910ddb8c51d4cabeaec77e6 AS files

# Which release this is and where its images are. Written into /kern/RELEASE, which the installer and
# `make update` read instead of a git tag — there is no git on a server installed this way.
ARG KERN_VERSION
ARG KERN_IMAGES
ARG KERN_IMAGES_SIGNER

WORKDIR /kern

# The compose files a server can run. The `.dev` overlays are removed below: a production COMPOSE_FILE
# never contains one.
COPY compose.yaml ./
COPY compose.services/ compose.services/

# What compose mounts from the host, read-only, into the containers.
COPY docker/postgres/init/ docker/postgres/init/
COPY docker/postgres/pg_hba.conf docker/postgres/
COPY docker/tls/kern-tls.sh docker/tls/
COPY docker/tls/trusted/.gitignore docker/tls/trusted/
COPY docker/clamav/clamd.conf docker/clamav/
COPY docker/openbao/config.hcl docker/openbao/entrypoint.sh docker/openbao/
COPY docker/redis/entrypoint.sh docker/redis/
COPY docker/php/scheduler-healthcheck.php docker/php/
# The folder only: a certificate of the server's own is put there by the installer, never shipped.
COPY docker/caddy/certs/.gitkeep docker/caddy/certs/

# The installer, the updater and the signature check; the server's Makefile is docker/install/Makefile.
COPY scripts/install.sh scripts/update.sh scripts/verify-images.sh scripts/host-addresses.sh scripts/
COPY docker/install/Makefile ./

# The environment file's template: the application's, then each package's.
COPY apps/api/.env.example apps/api/
COPY packages/ /tmp/packages/

RUN set -eu; \
    test -n "$KERN_VERSION" && test -n "$KERN_IMAGES" && test -n "$KERN_IMAGES_SIGNER"; \
    rm -f compose.services/*.dev.yaml; \
    for example in /tmp/packages/*/.env.example; do \
        package="${example#/tmp/packages/}"; \
        mkdir -p "packages/${package%/.env.example}"; \
        cp "$example" "packages/$package"; \
    done; \
    rm -rf /tmp/packages; \
    # compose reads the project folder's .env for COMPOSE_FILE; it is the installation's own file.
    ln -s apps/api/.env .env; \
    printf 'KERN_VERSION=%s\nKERN_IMAGES=%s\nKERN_IMAGES_SIGNER=%s\n' \
        "$KERN_VERSION" "$KERN_IMAGES" "$KERN_IMAGES_SIGNER" > RELEASE

FROM scratch
COPY --from=files /kern /kern
