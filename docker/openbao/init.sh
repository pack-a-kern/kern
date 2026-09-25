#!/bin/sh
# TaxOS România — OpenBao dev-mode bootstrap.
#
# POSIX /bin/sh, not bash: openbao/openbao:2.6.2 is Alpine-based and has no
# bash — confirmed 2026-09-07.
#
# Runs once (as the `openbao-init` one-shot compose service) against the
# `openbao` dev-mode server, enables the transit secrets engine, and creates
# the KEK the application will use for per-TaxSubject envelope encryption
# (BLUEPRINT.md §4.2). Idempotent — safe to re-run on every `docker compose up`.
#
# DEV MODE ONLY. Dev mode storage is in-memory: every restart of the
# `openbao` container wipes it and this script must run again (compose
# depends_on makes that automatic). Production must NOT use dev mode — see
# the "OpenBao" section of docs/handoff/001-docker-topology.md.
#
# Key type: chacha20-poly1305 (OpenBao/Vault transit's own IETF ChaCha20-
# Poly1305, 96-bit nonce) is the closest transit-engine equivalent to
# BLUEPRINT.md §4.2's XChaCha20-Poly1305 preference. It is not literally
# XChaCha20 (192-bit nonce) — OpenBao's transit engine does not expose that
# construction — but it wraps/unwraps the per-subject DEK only; application-
# level field encryption is a separate, later concern (Milestone 1) and can
# still use real XChaCha20-Poly1305 via libsodium in application code.
# Verified against a real running container 2026-09-07 — see handoff.

set -eu

: "${BAO_ADDR:?BAO_ADDR must be set}"
: "${BAO_TOKEN:?BAO_TOKEN must be set}"
KERN_KEK_NAME="${KERN_KEK_NAME:-tax-subject-kek}"

echo "openbao-init: waiting for ${BAO_ADDR} to report healthy..."
until bao status >/dev/null 2>&1; do
    sleep 1
done

if bao secrets list -format=json | grep -q '"transit/"'; then
    echo "openbao-init: transit already enabled, skipping."
else
    echo "openbao-init: enabling transit secrets engine."
    bao secrets enable transit
fi

echo "openbao-init: ensuring KEK '${KERN_KEK_NAME}' exists (type=chacha20-poly1305)."
bao write -f "transit/keys/${KERN_KEK_NAME}" type=chacha20-poly1305

echo "openbao-init: done."
