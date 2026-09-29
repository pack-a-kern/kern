#!/bin/sh
# Starts OpenBao, then makes sure it is usable before anything else tries to use it.
#
# ## Why this replaced a separate init container
#
# There used to be a one-shot `openbao-init` service that enabled the transit engine and created the
# key. It ran once, exited 0, and had no restart policy — so when the host came back from a kernel
# panic, Docker restarted `openbao` (restart: unless-stopped) and did not restart the init container.
# OpenBao came up with no transit engine at all, reported itself healthy for fourteen hours, and
# every decrypt returned 404. Nothing in the stack noticed.
#
# A reboot never runs `docker compose up`, so anything a reboot must not skip cannot live in a
# container that only `up` starts. It lives here instead, in the server's own startup, so "openbao is
# running" and "the key exists" became one statement rather than two.
#
# ## What it does, in order, and all of it idempotent
#
# 1. starts the server in the background, and waits for its listener;
# 2. initialises it on first boot only, writing the unseal key and root token into the volume;
# 3. unseals it, every boot, because a real server always starts sealed;
# 4. enables the transit engine and creates the KEK, if they are not already there;
# 5. issues the application's own token with a policy that can use that one key and nothing else;
# 6. hands the foreground back to the server.
#
# ## The unseal key is in the volume, in plaintext
#
# Deliberately, and only for a deployment that accepts it. Anyone who can read the volume can unseal
# this server and decrypt everything in the database — so this is not protection against an attacker
# with filesystem access.
#
# It is not a step down from what was here before: `OPENBAO_TOKEN` sits in plaintext in
# `apps/api/.env` on the same disk, and it was the *root* token, so filesystem access already meant
# total control of the KMS. What envelope encryption protects against is a stolen *database* — a
# dump, a backup, a replica, an injection — which carries wrapped data keys and no master key, and
# that property is untouched by where the unseal key lives, because it does not live in the database.
#
# Production wants a real answer: auto-unseal against a cloud KMS, or Shamir shares held by people.
# `docs/DEPLOYMENT.md` §7 carries that decision. This file makes a development installation survive a
# reboot, which is the failure that actually occurred.
set -eu

BAO_ADDR="${BAO_ADDR:-http://127.0.0.1:8200}"
export BAO_ADDR

DATA_DIR="/openbao/file"
INIT_FILE="${DATA_DIR}/init.json"
KEK_NAME="${KERN_KEK_NAME:-tax-subject-kek}"
TRANSIT_MOUNT="${KERN_TRANSIT_MOUNT:-transit}"
APP_POLICY="kern-app"

log() { echo "openbao-entrypoint: $*"; }

bao server -config=/openbao/config.hcl &
SERVER_PID=$!

# The listener answers before the server is initialised, and `bao status` exits non-zero while
# sealed — so readiness is "the HTTP API responds at all", not "status is clean".
log "waiting for the listener"
until bao status >/dev/null 2>&1 || bao status 2>&1 | grep -q 'Sealed\|not yet initialized'; do
    # A server that died did so over its configuration, and waiting longer will not change that. Exit
    # with its own status so the restart policy backs off instead of this looping in the foreground.
    if ! kill -0 "$SERVER_PID" 2>/dev/null; then
        log "the server exited before it was ready — see its output above"
        wait "$SERVER_PID"
        exit 1
    fi
    sleep 1
done

if ! bao status 2>&1 | grep -q 'Initialized.*true'; then
    log "first boot: initialising with a single unseal key"
    # One share, threshold one: splitting a key across shares is only meaningful when the shares go
    # to different people, and here they would all land in the same file.
    bao operator init -key-shares=1 -key-threshold=1 -format=json > "${INIT_FILE}.tmp"
    chmod 600 "${INIT_FILE}.tmp"
    mv "${INIT_FILE}.tmp" "$INIT_FILE"
else
    log "already initialised"
fi

if [ ! -f "$INIT_FILE" ]; then
    log "FATAL: ${INIT_FILE} is missing but this server is initialised — the unseal key is gone."
    log "Nothing here can recover it. Every wrapped data key in the database is unreadable."
    exit 1
fi

# Neither value is printed, at any point: the log of a KMS container is not a place for its own keys.
#
# Whitespace is stripped before matching because `bao operator init -format=json` pretty-prints, so the
# unseal key sits on the line *after* its own field name and no single-line pattern can reach it. There
# is no `jq` in this image to do it properly. Base64 and a token contain no spaces, so collapsing the
# document is safe for the two values being read out of it.
INIT_FLAT="$(tr -d ' \t\n' < "$INIT_FILE")"
UNSEAL_KEY="$(printf '%s' "$INIT_FLAT" | sed -n 's/.*"unseal_keys_b64":\["\([^"]*\)".*/\1/p')"
ROOT_TOKEN="$(printf '%s' "$INIT_FLAT" | sed -n 's/.*"root_token":"\([^"]*\)".*/\1/p')"

if [ -z "$UNSEAL_KEY" ] || [ -z "$ROOT_TOKEN" ]; then
    log "FATAL: could not read the unseal key or root token out of ${INIT_FILE}."
    exit 1
fi

if bao status 2>&1 | grep -q 'Sealed.*true'; then
    log "unsealing"
    bao operator unseal "$UNSEAL_KEY" >/dev/null
else
    log "already unsealed"
fi

BAO_TOKEN="$ROOT_TOKEN"
export BAO_TOKEN

if bao secrets list -format=json | grep -q "\"${TRANSIT_MOUNT}/\""; then
    log "transit already enabled at ${TRANSIT_MOUNT}/"
else
    log "enabling transit at ${TRANSIT_MOUNT}/"
    bao secrets enable -path="$TRANSIT_MOUNT" transit
fi

# `-f` on an existing key is a no-op create, not a rotation: an existing KEK keeps its versions, so
# every data key wrapped under it still unwraps. That is what makes this safe to run on every boot.
log "ensuring KEK '${KEK_NAME}' exists"
bao write -f "${TRANSIT_MOUNT}/keys/${KEK_NAME}" type=chacha20-poly1305 >/dev/null

# The four paths `Kern\Platform\KeyManagement\OpenBaoTransitKeyManagementService` actually calls, and
# nothing else. The application used to present the root token, which could read every secret in the
# server and create more tokens; it now cannot even list the engines.
log "writing policy '${APP_POLICY}'"
bao policy write "$APP_POLICY" - >/dev/null <<POLICY
path "${TRANSIT_MOUNT}/encrypt/${KEK_NAME}" {
  capabilities = ["update"]
}
path "${TRANSIT_MOUNT}/decrypt/${KEK_NAME}" {
  capabilities = ["update"]
}
path "${TRANSIT_MOUNT}/keys/${KEK_NAME}" {
  capabilities = ["read"]
}
path "${TRANSIT_MOUNT}/keys/${KEK_NAME}/rotate" {
  capabilities = ["update"]
}
POLICY

# The token id the application already presents, so `OPENBAO_TOKEN` in the environment file keeps
# working and there is still one place that value is written down. Periodic rather than expiring: a
# token that dies mid-month would take every encrypted read with it, and renewal is a moving part
# this installation does not have. Creating it again with the same id is an error once it exists, so
# the lookup comes first.
if [ -n "${KERN_APP_TOKEN:-}" ]; then
    if bao token lookup "$KERN_APP_TOKEN" >/dev/null 2>&1; then
        log "application token already present"
    else
        log "issuing the application token against policy '${APP_POLICY}'"
        bao token create -id="$KERN_APP_TOKEN" -policy="$APP_POLICY" -period=768h -orphan >/dev/null
    fi
else
    log "WARNING: KERN_APP_TOKEN is unset — the application has no token of its own."
fi

unset BAO_TOKEN UNSEAL_KEY ROOT_TOKEN INIT_FLAT

log "ready; handing the foreground back to the server"
wait "$SERVER_PID"
