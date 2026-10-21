#!/bin/sh
# Which pinned images have a newer build upstream. Usage: make pins
#
# Every image in compose and the Dockerfiles is pinned by tag and digest (docs/DEPENDENCY-POLICY.md §1).
# A registry rebuilds a tag under the same name — `redis:8.10.1-trixie` today is not the one pulled a
# month ago — so a digest stays on the build that was tested, and security patches to the OS underneath
# arrive only when the pin is moved. This asks each registry where every pinned tag points now and
# prints the pins that are behind. It changes nothing: moving a pin is checked and recorded like any
# version bump, in docs/reference/verified-versions.md.
#
# It reports newer builds of the same tag, not newer versions; those are checked by hand, per §2.
# Exit status 1 when any pin is behind or could not be checked, so CI can say so.

set -eu

ROOT="$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
cd "$ROOT"

docker buildx version >/dev/null 2>&1 || {
    printf 'docker buildx is needed to read a registry without pulling.\n' >&2
    exit 2
}

PINS="$(grep -hoE '[a-z0-9][a-z0-9./_-]*:[A-Za-z0-9._-]+@sha256:[0-9a-f]{64}' \
    compose.yaml compose.dev.yaml compose.services/*.yaml docker/*/Dockerfile | sort -u)"

behind=0
for pin in $PINS; do
    tag="${pin%@*}"
    pinned="${pin#*@}"
    current="$(docker buildx imagetools inspect "$tag" 2>/dev/null | sed -n 's/^Digest: *//p' | head -n 1)"

    if [ -z "$current" ]; then
        printf '%-48s could not be checked\n' "$tag"
        behind=1
    elif [ "$current" = "$pinned" ]; then
        printf '%-48s current\n' "$tag"
    else
        printf '%-48s newer build: %s\n' "$tag" "$current"
        behind=1
    fi
done

exit "$behind"
