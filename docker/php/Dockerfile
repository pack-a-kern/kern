# TaxOS România — app / queue / scheduler / reverb image (Laravel 13, PHP 8.5).
#
# Base image, PHP extensions and Composer version are all pinned per
# docs/reference/verified-versions.md and docs/DEPENDENCY-POLICY.md — each image
# by exact tag and digest, so a rebuild of the tag upstream changes nothing here
# until `make pins` reports it and the pin is moved. Do not float any tag.
#
# sodium (XChaCha20-Poly1305) and --with-password-argon2 (Argon2id) already
# ship enabled in php:8.5-fpm-trixie — see verified-versions.md. Do not
# recompile PHP or add libsodium; only pdo_pgsql, redis, bcmath, intl are
# added here (BLUEPRINT.md §1.2 scope for this image).
#
# apps/api is built by a parallel agent in this same repo. Every stage below
# has been built and boot-tested end to end against the real
# apps/api/composer.json + composer.lock (2026-09-07) — see
# docs/handoff/001-docker-topology.md "How to verify".

# ---------------------------------------------------------------------------
# Stage: ext-builder — compile the four required PHP extensions in isolation
# from a build toolchain, so the final runtime image never carries a compiler.
# ---------------------------------------------------------------------------
FROM php:8.5.12-fpm-trixie@sha256:baf3ea13a8207fa18e1011bad1016e8b5e59fc974c6966d0efa6f36a16f875f0 AS ext-builder

# Exact apt candidate versions for trixie, checked 2026-09-07 (see handoff
# "Sources consulted"). Pinning apt packages by version, per
# docs/DEPENDENCY-POLICY.md §1.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev=17.11-0+deb13u1 \
        libicu-dev=76.1-4 \
        libjpeg62-turbo-dev=1:2.1.5-4 \
        libpng-dev=1.6.48-1+deb13u6 \
        libwebp-dev=1.5.0-0.1 \
    && rm -rf /var/lib/apt/lists/*

# pecl redis pinned to the latest stable release verified 2026-09-07
# (pecl.php.net/rest/r/redis/allreleases.xml -> 6.3.0). bcmath/pdo_pgsql/intl
# ship in the PHP source tree bundled with the base image; no version pin
# beyond the base image tag applies to them.
#
# pcntl is also added here even though BLUEPRINT.md §1.2's extension list names
# only pdo_pgsql/redis/bcmath/intl: apps/api/composer.lock (laravel/horizon
# 5.48.3) hard-requires ext-pcntl for its signal-handling process
# supervision, confirmed by `composer install` failing without it
# (see docs/handoff/001-docker-topology.md "Key decisions"). It ships in the
# PHP source tree like bcmath, so needs no extra apt package or pin.
# gd is configured before it is built: without --with-jpeg/--with-webp it compiles to a PNG-only
# extension that reports no error, and imagecreatefromjpeg() is then simply an undefined function at
# runtime. Avatars decode whatever a phone or a desktop produces, so all three codecs are required.
#
# No --with-freetype: that is for rendering text into an image, which nothing here does. Avatar
# processing decodes, crops, resamples and re-encodes — the font stack would be surface for nothing.
RUN docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql bcmath intl pcntl gd \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis

# ---------------------------------------------------------------------------
# Stage: composer — pinned Composer binary, used only to vendor apps/api.
# ---------------------------------------------------------------------------
FROM composer:2.10.3@sha256:2cf275428757e5435a667bfc0d038b14520ca02be2b1b70d44a05c8c19e2de48 AS composer-bin

# ---------------------------------------------------------------------------
# Stage: vendor — install PHP dependencies against the real lockfile.
# Built FROM ext-builder, not the bare base image: composer's platform check
# resolves against the PHP binary that runs it, and laravel/horizon
# 5.48.3 hard-requires ext-pcntl (see the ext-builder comment above) — using
# the bare image here fails composer install even though the extension is
# present in the final runtime image.
# ---------------------------------------------------------------------------
FROM ext-builder AS vendor
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
# unzip: composer's dist downloader needs it to extract package archives.
# Not needed in the final runtime image, so it stays confined to this stage.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip=6.0-29+deb13u1 \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
# The local packages first: composer.json requires kern/* from a path repository at
# `../../packages/*`, which from /var/www/html is exactly /var/packages. Without them here,
# `composer install` cannot resolve its own lock file.
COPY packages /var/packages
COPY apps/api/composer.json apps/api/composer.lock ./
# The AWS SDK down to the services composer.json's `extra.aws/aws-sdk-php` lists (S3, plus the ones the
# SDK itself never deletes — STS, SSO, KMS — for credentials): the install runs without scripts, so the
# trim is asked for by name, in this same layer, and the ~400 unused services never enter the image.
RUN composer install \
        --no-interaction --no-dev --no-scripts --no-autoloader --prefer-dist \
    && composer run-script pre-autoload-dump --no-interaction

# ---------------------------------------------------------------------------
# Stage: runtime — the actual app/queue/scheduler/reverb image.
# ---------------------------------------------------------------------------
FROM php:8.5.12-fpm-trixie@sha256:baf3ea13a8207fa18e1011bad1016e8b5e59fc974c6966d0efa6f36a16f875f0 AS runtime

# Extensions compiled in ext-builder, copied in (no compiler in this stage).
COPY --from=ext-builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=ext-builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d

# Runtime shared libraries the compiled extensions above link against
# (libpq.so.5 for pdo_pgsql, libicuio.so.76/friends for intl) — ext-builder
# only produced the .so extension files, not their own native dependencies.
# Confirmed necessary by a real boot test: without these, php-fpm starts but
# logs "Unable to load dynamic library" for both extensions.
#
# libfcgi-bin: fcgi client used purely for the FastCGI healthcheck against
# php-fpm's own status page (works even before apps/api's routes exist —
# see fpm pool config's pm.status_path).
#
# First, the compiler: the official image keeps its own build tools ($PHPIZE_DEPS — gcc, g++,
# make, autoconf and the kernel headers) so `docker-php-ext-install` can run in it. libc6-dev is
# named as well, because the list calls it `libc-dev`. Nothing is compiled here — ext-builder did
# that — so they go, before the libraries below are installed and so before auto-remove could
# count those as unused. CI's Trivy scan found 45 HIGH/CRITICAL advisories in the kernel headers
# alone (2026-11-03).
#
# zlib1g is upgraded past the base image's: its 1:1.3.dfsg+really1.3.1-1+b1 has CVE-2026-85091 and
# CVE-2026-76844, fixed in Debian's +deb13u1, and php:8.5.12-fpm-trixie has no newer build yet. Drop it
# from the list once `make pins` reports a build that ships it.
RUN apt-get purge -y --auto-remove $PHPIZE_DEPS libc6-dev \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq5=17.11-0+deb13u1 \
        libicu76=76.1-4 \
        libjpeg62-turbo=1:2.1.5-4 \
        libpng16-16t64=1.6.48-1+deb13u6 \
        libwebp7=1.5.0-0.1 \
        libfcgi-bin=2.4.5-0.1 \
        zlib1g=1:1.3.dfsg+really1.3.1-1+deb13u1 \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-kern.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

RUN groupadd --gid 10001 appuser \
    && useradd --uid 10001 --gid appuser --no-create-home --shell /usr/sbin/nologin appuser \
    && mkdir -p /var/www/html/storage /var/www/html/bootstrap/cache /run/php \
    && chown -R appuser:appuser /var/www/html /run/php

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
COPY --from=vendor --chown=appuser:appuser /var/www/html/vendor /var/www/html/vendor
# `vendor/kern/*` are symlinks into /var/packages (a path repository installed with symlink: true,
# so that editing a package in development needs no reinstall). Copied from the vendor stage rather
# than from the build context, so the image carries exactly the sources that were resolved.
COPY --from=vendor --chown=appuser:appuser /var/packages /var/packages
COPY --chown=appuser:appuser apps/api /var/www/html

WORKDIR /var/www/html

# The vendor stage installed with --no-autoloader (it only has
# composer.json/lock, not app/ or database/factories/ — the other halves of
# the PSR-4 map). Generate the real, optimized autoloader now that both
# vendor/ and the application source are both present in this stage.
# Confirmed necessary by a real boot test: `php artisan --version` fails
# with vendor/autoload.php missing without this step.
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && chown -R appuser:appuser /var/www/html/vendor /var/www/html/bootstrap \
    && rm -f /usr/bin/composer

USER appuser
CMD ["php-fpm", "--nodaemonize"]

# ---------------------------------------------------------------------------
# Stage: test — adds dev dependencies (Pint, PHPStan, Pest, deptrac) for
# `make test`/`make lint`. Never used as a runtime image — the `runtime`
# stage above stays free of dev tooling.
# ---------------------------------------------------------------------------
FROM vendor AS test
# The runtime's limits, so a test that passes here also fits in production: without this the suite
# ran under PHP's 128M default against an application configured for 256M.
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-kern.ini
WORKDIR /var/www/html
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist
COPY apps/api /var/www/html
# An empty .env, as CI's test job makes one: with none at all Laravel's loader warns once per test,
# and Pest reports every test as a warning instead of a pass. Every value comes from phpunit.xml.
RUN touch .env && composer dump-autoload --optimize

