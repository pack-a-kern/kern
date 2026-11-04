#!/bin/sh
# Is a release's kern-install image enough to run the release, and nothing more? Usage:
#   scripts/tests/release-bundle.sh <image>        e.g. kern-install:ci, after building it
#
# docker/install/Dockerfile lists the files a server gets by hand. This copies them out of the image
# exactly as a server does and fails if:
#   - any path compose mounts from the host is missing (the list fell behind the compose files);
#   - any script the Makefile or the scripts call is missing;
#   - RELEASE is missing or incomplete;
#   - anything is there that must never ship: a `.dev` compose file, a key or certificate, an
#     environment file, or source code.
# Run by CI on every push, before the image could ever be published.

set -eu

IMAGE="${1:?usage: scripts/tests/release-bundle.sh <image>}"

BUNDLE="$(mktemp -d)"
trap 'rm -rf "$BUNDLE"' EXIT

CONTAINER="$(docker create "$IMAGE" x)"
docker cp "$CONTAINER:/kern/." "$BUNDLE" >/dev/null
docker rm "$CONTAINER" >/dev/null

failed=0
fail() {
    printf '  FAIL  %s\n' "$1"
    failed=1
}

# Every bind mount of every service a server can run, resolved inside the bundle.
set -- -f "$BUNDLE/compose.yaml"
for file in "$BUNDLE"/compose.services/*.yaml; do
    set -- "$@" -f "$file"
done
SOURCES="$(docker compose --project-directory "$BUNDLE" "$@" --env-file /dev/null \
    config --no-interpolate --format json \
    | jq -r '.services[] | (.volumes // [])[] | select(.type == "bind") | .source' | sort -u)"
[ -n "$SOURCES" ] || fail 'compose names no bind mount at all — the check itself is broken'
for source in $SOURCES; do
    [ -e "$source" ] || fail "compose mounts ${source#"$BUNDLE"/}, which the bundle does not have"
done

# Every script the server's Makefile and the scripts themselves call.
MISSING="$(grep -ohE 'scripts/[A-Za-z0-9_-]+\.sh' "$BUNDLE/Makefile" "$BUNDLE"/scripts/*.sh | sort -u | while IFS= read -r script; do
    case "$script" in
        scripts/generate-certs.sh | scripts/pins.sh) ;; # named in comments only, never called
        *) [ -x "$BUNDLE/$script" ] || printf '%s\n' "$script" ;;
    esac
done)"
for script in $MISSING; do
    fail "$script is called but not shipped, or not executable"
done

for key in KERN_VERSION KERN_IMAGES KERN_IMAGES_SIGNER; do
    grep -Eq "^$key=.+" "$BUNDLE/RELEASE" 2>/dev/null || fail "RELEASE has no $key"
done
[ -f "$BUNDLE/apps/api/.env.example" ] || fail 'apps/api/.env.example is missing — the installer builds .env from it'
ls "$BUNDLE"/packages/*/.env.example >/dev/null 2>&1 || fail "no package's .env.example is shipped"

FORBIDDEN="$(cd "$BUNDLE" && find . \( -name '*.dev.yaml' -o -name 'compose.dev.yaml' -o -name '*.key' -o -name '*.crt' \
    -o -name '*.pem' -o -path './apps/api/.env' -o -name '*.php' ! -path './docker/php/*' -o -name '*.ts' -o -name '*.tsx' \
    -o -name '*.py' -o -path './apps/api/app' -o -path './packages/*/src' -o -name '.git' \) -print)"
for path in $FORBIDDEN; do
    fail "${path#./} must not ship"
done

if [ "$failed" -ne 0 ]; then
    printf 'The release bundle is wrong; docker/install/Dockerfile lists what it holds.\n' >&2
    exit 1
fi
printf 'Release bundle: every mount, script and template present; nothing that must not ship.\n'
