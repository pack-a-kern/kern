#!/bin/sh
# Refuse to run images this project's CI did not build. Usage: scripts/verify-images.sh
#
# An installation that pulls its images (KERN_IMAGES set) trusts whatever the registry hands it. A
# registry account, a mirror or a token can be taken over, and the image under a tag replaced; the
# tag would still read 0.1.0. CI signs each image it publishes (keyless, .github/workflows/ci.yml),
# and the signature's certificate names the repository, the workflow and the tag that built it.
# This checks every pulled kern-* image against exactly those three, by the digest that was pulled
# — not by tag, which can move between the check and the start. Anything else stops here.
#
# Run after `docker compose pull`, before `up`: the installer and `make update` both do.
#
# Reads KERN_IMAGES, KERN_VERSION and KERN_IMAGES_SIGNER from the environment, or apps/api/.env.
# cosign runs from its own pinned image, so the host needs nothing installed. It needs to reach
# Sigstore's public services (the signature is checked against its transparency log).

set -eu

ROOT="$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
cd "$ROOT"

# cosign v3.1.4 (2026-10-20), pinned like every other image — docs/DEPENDENCY-POLICY.md §1.
COSIGN='ghcr.io/sigstore/cosign/cosign:v3.1.4@sha256:0f06154836d2de34f887a5c273506d52ca43edaa1d9e53e6510fa78a48f69c2d'
ISSUER='https://token.actions.githubusercontent.com'

die() {
    printf '%s\n' "$1" >&2
    exit 1
}

# The environment first, so `make update` can check a version before writing it to .env.
from_env_file() {
    sed -n "s/^$1=//p" apps/api/.env 2>/dev/null | tail -n 1
}
IMAGES="${KERN_IMAGES:-$(from_env_file KERN_IMAGES)}"
VERSION="${KERN_VERSION:-$(from_env_file KERN_VERSION)}"
SIGNER="${KERN_IMAGES_SIGNER:-$(from_env_file KERN_IMAGES_SIGNER)}"

[ -n "$IMAGES" ] || die 'KERN_IMAGES is empty: this installation builds its own images, and there is nothing to verify.'
[ -n "$VERSION" ] || die 'KERN_VERSION is empty: there is no release to check the images against.'
printf '%s' "$SIGNER" | grep -Eq '^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$' \
    || die 'KERN_IMAGES_SIGNER must name the GitHub repository whose CI signs the images, as owner/repository.'

# Exactly this workflow file, at exactly this tag. A signature from a branch, a pull request or
# another repository's workflow does not pass.
IDENTITY="https://github.com/$SIGNER/.github/workflows/ci.yml@refs/tags/v$VERSION"

# The images compose would start, so the list is never a copy that drifts from it.
REFS="$(KERN_IMAGES="$IMAGES" KERN_VERSION="$VERSION" docker compose config --images | grep -F "$IMAGES/kern-" | sort -u)"
[ -n "$REFS" ] || die "compose names no image under $IMAGES/."

# cosign asks the registry for the signature itself, so it needs the same login `docker compose pull`
# used when the images are private: the Docker config that `docker login` wrote, mounted read-only and
# read as the user who owns it (the cosign image's own user could not open a mode-600 file). Nothing is
# copied or kept; with no config, the images are public and nothing is mounted.
DOCKER_CONFIG_DIR="${DOCKER_CONFIG:-$HOME/.docker}"
set --
if [ -f "$DOCKER_CONFIG_DIR/config.json" ]; then
    set -- -v "$DOCKER_CONFIG_DIR/config.json:/docker/config.json:ro" -e DOCKER_CONFIG=/docker \
        -u "$(id -u):$(id -g)" -e HOME=/tmp
fi

failed=0
for ref in $REFS; do
    repo="${ref%:*}"
    digest="$(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$ref" 2>/dev/null \
        | grep -F "$repo@" | head -n 1)"

    if [ -z "$digest" ]; then
        printf '  %-60s not pulled\n' "$ref"
        failed=1
    elif out="$(docker run --rm "$@" "$COSIGN" verify \
        --certificate-identity "$IDENTITY" \
        --certificate-oidc-issuer "$ISSUER" \
        "$digest" 2>&1)"; then
        printf '  %-60s signed by %s\n' "$ref" "$SIGNER"
    else
        printf '  %-60s NOT SIGNED by %s at v%s\n' "$ref" "$SIGNER" "$VERSION"
        # cosign's own reason: no signature, a signature by someone else, or no network to check.
        printf '%s\n' "$out" | tail -n 2 | sed 's/^/      /'
        failed=1
    fi
done

[ "$failed" -eq 0 ] || die '
At least one image is not the one CI published for this release. Nothing was started.'
