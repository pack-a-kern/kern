#!/bin/sh
# Move an installation made from a release's files to another release. Usage: scripts/update.sh 1.4.0
# (or `make update VERSION=1.4.0`, which is this).
#
# Two halves, and the second is always the new release's own:
#
#   1. Fetch ghcr.io/<owner>/kern-install:<version>, the release's files (docker/install/Dockerfile),
#      and check CI's signature on it with the verifier THIS release brought — the one already trusted
#      — before a single file is copied out. Then put each file in place and hand over to the new
#      release's copy of this script.
#   2. As the new release: pull its images and check theirs, record the version, migrate, recreate.
#
# Nothing changes if either signature check fails. The installation's own files are never touched:
# apps/api/.env, docker/caddy/certs/ and docker/tls/trusted/ hold what the installer wrote there, and
# the release ships those folders empty.
#
# A clone that builds its own images has no RELEASE file and is moved the git way (git pull, make
# build, make up); this says so rather than guessing.

set -eu

ROOT="$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
cd "$ROOT"

ENV_FILE=apps/api/.env

die() {
    printf '%s\n' "$1" >&2
    exit 1
}

release_value() {
    sed -n "s/^$1=//p" RELEASE | tail -n 1
}

VERSION="${1:-}"
PHASE="${2:-fetch}"

printf '%s' "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$' \
    || die 'Usage: make update VERSION=1.4.0'
[ -f RELEASE ] \
    || die 'There is no RELEASE file here, so this installation was not made from a release: it builds its own images. Move it with git pull, make build, make up.'
[ -f "$ENV_FILE" ] || die "$ENV_FILE is missing — this folder was never installed (make install)."

IMAGES="$(release_value KERN_IMAGES)"
SIGNER="$(release_value KERN_IMAGES_SIGNER)"
if [ -z "$IMAGES" ] || [ -z "$SIGNER" ]; then
    die 'RELEASE names no registry or signer. Copy it again from the release this installation came from.'
fi

if [ "$PHASE" = fetch ]; then
    BUNDLE="$IMAGES/kern-install:$VERSION"
    printf '── Fetching the files of release %s\n' "$VERSION"
    docker pull --quiet "$BUNDLE" >/dev/null

    printf '── Checking they were signed by the CI that built them\n'
    KERN_IMAGES="$IMAGES" KERN_IMAGES_SIGNER="$SIGNER" KERN_VERSION="$VERSION" scripts/verify-images.sh "$BUNDLE"

    # Copied out by the digest that was checked, never the tag, which can move in between.
    DIGEST="$(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$BUNDLE" \
        | grep -F "$IMAGES/kern-install@" | head -n 1)"
    [ -n "$DIGEST" ] || die "$BUNDLE has no registry digest to copy from."

    STAGE="$(mktemp -d "$ROOT/.release.XXXXXX")"
    trap 'rm -rf "$STAGE"' EXIT
    # Created, never started: the image has no program to start. `x` is only there because `create`
    # wants a command.
    CONTAINER="$(docker create "$DIGEST" x)"
    docker cp "$CONTAINER:/kern/." "$STAGE" >/dev/null
    docker rm "$CONTAINER" >/dev/null

    [ "$(sed -n 's/^KERN_VERSION=//p' "$STAGE/RELEASE")" = "$VERSION" ] \
        || die "The files fetched say they are not release $VERSION. Nothing was changed."

    # Each file renamed into place, so a script that is running — this one included — keeps reading
    # the copy it started with. Folders are created; nothing already here is deleted.
    (cd "$STAGE" && find . -type d) | while IFS= read -r folder; do
        mkdir -p "$folder"
    done
    (cd "$STAGE" && find . ! -type d) | while IFS= read -r file; do
        mv -f "$STAGE/$file" "$file"
    done
    printf '   the files of release %s are in place\n' "$VERSION"
    # `exec` replaces this process, so the trap would never run.
    rm -rf "$STAGE"
    trap - EXIT

    # The rest is the new release's to do: its compose files, its verifier, its migrations.
    exec scripts/update.sh "$VERSION" images
fi

[ "$PHASE" = images ] || die "Unknown step [$PHASE]."

printf '── Pulling the images of release %s\n' "$VERSION"
KERN_VERSION="$VERSION" docker compose pull

printf '── Checking each image was signed by the CI that built it\n'
# Before the environment file names the new version: a release that fails leaves the old one running.
KERN_VERSION="$VERSION" scripts/verify-images.sh

# In place, so the file keeps its owner, group and mode.
TMP="$(mktemp "$ENV_FILE.XXXXXX")"
awk -v v="$VERSION" '/^KERN_VERSION=/ { print "KERN_VERSION=" v; done = 1; next } { print } END { if (!done) print "KERN_VERSION=" v }' "$ENV_FILE" > "$TMP"
cat "$TMP" > "$ENV_FILE"
rm -f "$TMP"

printf '── Migrating and starting release %s\n' "$VERSION"
docker compose run --rm -T migrate
docker compose up -d --no-build --remove-orphans --wait
docker compose exec -T app php artisan kern:access:sync
# A release can add a disk, and a bucket nothing created is an upload that fails with no reason given.
docker compose exec -T app php artisan storage:buckets --create
docker compose exec -T app php artisan kern:health
