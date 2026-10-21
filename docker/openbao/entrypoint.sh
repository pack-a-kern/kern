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
# 1. starts the server in the background, with the certificate docker/certs issued it, and waits for
#    its listener;
# 2. initialises it on first boot only, writing the unseal key and root token into the volume;
# 3. unseals it, every boot, because a real server always starts sealed;
# 4. enables the transit engine and creates the KEK, if they are not already there;
# 5. issues the application's own token with a policy that can use that one key and nothing else,
#    publishes it to the PHP containers, and replaces it daily once it nears expiry;
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

DATA_DIR="/openbao/file"

# The CLI in this container talks to the server over the same TLS listener as everyone else, trusting
# the stack's CA (docker/certs), whose bundle sits beside this server's certificate. 127.0.0.1 is one
# of the names on the certificate.
BAO_ADDR="${BAO_ADDR:-https://127.0.0.1:8200}"
BAO_CACERT="${BAO_CACERT:-/run/kern/tls/ca.crt}"
export BAO_ADDR BAO_CACERT

INIT_FILE="${DATA_DIR}/init.json"
KEK_NAME="${KERN_KEK_NAME:-tax-subject-kek}"
BLIND_INDEX_KEY_NAME="${KERN_BLIND_INDEX_KEY_NAME:-blind-index}"
TRANSIT_MOUNT="${KERN_TRANSIT_MOUNT:-transit}"
APP_POLICY="kern-app"

log() { echo "openbao-entrypoint: $*"; }

# Written to a temporary name and moved, so a reader never sees half a file.
write_secret() {
    printf '%s' "$2" > "$1.tmp" && chmod "$3" "$1.tmp" && mv "$1.tmp" "$1"
}

bao server -config=/openbao/config.hcl &
SERVER_PID=$!

# The listener's certificate is issued and renewed by docker/certs; a renewed one is picked up with a
# SIGHUP, which OpenBao answers by reloading it without dropping a connection.
( sh /usr/local/bin/kern-tls watch "$SERVER_PID" HUP & )

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

# The key every blind index is computed with. An HMAC key of its own, not the KEK: it is rotated on
# its own schedule, and rotating it means re-indexing rather than re-wrapping. Same no-op-if-present
# create as above. It used to live in `apps/api/.env` — docs/adr/0020-blind-index-key-in-the-kms.md.
log "ensuring blind-index key '${BLIND_INDEX_KEY_NAME}' exists"
bao write -f "${TRANSIT_MOUNT}/keys/${BLIND_INDEX_KEY_NAME}" type=hmac key_size=32 >/dev/null

# The paths `Kern\Platform\KeyManagement\OpenBaoTransitKeyManagementService` actually calls, and
# nothing else. It can compute with the blind-index key and read its version, but not rotate it:
# rotating that key makes every stored index stale until a re-index runs, which is an operator's
# decision, not something a compromised application should be able to trigger. The application used to present the root token, which could read every secret in the
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
path "${TRANSIT_MOUNT}/hmac/${BLIND_INDEX_KEY_NAME}/sha2-256" {
  capabilities = ["update"]
}
path "${TRANSIT_MOUNT}/keys/${BLIND_INDEX_KEY_NAME}" {
  capabilities = ["read"]
}
POLICY

# ## The application's token: issued here, replaced here, never written into apps/api/.env
#
# It used to be a value the installer invented and wrote into `.env`, registered here with that id,
# and periodic with a 32-day period — and nothing ever renewed it, so it was due to die on 2026-10-31
# and take every encrypted read with it. Renewing would only have kept the same value alive forever.
#
# So the token is *replaced* instead. Once a day this script checks how long the current one has left;
# with `KERN_APP_TOKEN_ROTATE_DAYS` or fewer it issues a new one, publishes it, and revokes the old one
# on the following check — by which time every process is already using the new one, because they read
# the file on each call rather than once at start-up. A failed check changes nothing and is tried again
# the next day, with days of margin left: that margin is the whole reason for rotating early.
#
# Published to `/openbao/app-token/token`, on a memory-only volume that only the PHP containers that
# talk to this server mount (compose.services/openbao.yaml). Kept on disk only here, in this server's
# own volume, so a restart can publish the same token again — that volume already holds the root token
# and the unseal key, so it is not a new place for a secret to be.
APP_TOKEN_PUBLISHED="/openbao/app-token/token"
APP_TOKEN_CURRENT="${DATA_DIR}/app-token"
APP_TOKEN_PREVIOUS="${DATA_DIR}/app-token.previous"
ROTATE_DAYS="${KERN_APP_TOKEN_ROTATE_DAYS:-7}"
CHECK_SECONDS="${KERN_APP_TOKEN_CHECK_SECONDS:-86400}"

# Seconds the token has left, or nothing if the server does not know it — revoked, expired, never
# issued. Read with the root token, so the answer does not depend on the token being asked about.
seconds_left() {
    BAO_TOKEN="$ROOT_TOKEN" bao token lookup -format=json "$1" 2>/dev/null \
        | tr -d ' \t\n' | sed -n 's/.*"ttl":\([0-9]*\).*/\1/p'
}

# Every step checks its own result instead of relying on `set -e`: this also runs from the daily loop,
# where a failure must leave the working token in place rather than publish an empty one.
ensure_app_token() {
    if [ -f "$APP_TOKEN_PREVIOUS" ]; then
        BAO_TOKEN="$ROOT_TOKEN" bao token revoke "$(cat "$APP_TOKEN_PREVIOUS")" >/dev/null 2>&1 || true
        rm -f "$APP_TOKEN_PREVIOUS"
        log "revoked the application token replaced at the last check"
    fi

    current=""
    [ -f "$APP_TOKEN_CURRENT" ] && current="$(cat "$APP_TOKEN_CURRENT")"

    # An installation from before this script issued the token: the value in `.env` is still the one in
    # use, so it is adopted rather than cut off — and replaced like any other once it nears the end.
    if [ -z "$current" ] && [ -n "${KERN_APP_TOKEN:-}" ] && [ -n "$(seconds_left "$KERN_APP_TOKEN")" ]; then
        current="$KERN_APP_TOKEN"
        write_secret "$APP_TOKEN_CURRENT" "$current" 600 || return 1
        log "adopted the application token from the environment file"
    fi

    left=""
    [ -n "$current" ] && left="$(seconds_left "$current")"

    if [ -z "$left" ] || [ "$left" -le $((ROTATE_DAYS * 86400)) ]; then
        replacement="$(BAO_TOKEN="$ROOT_TOKEN" bao token create -policy="$APP_POLICY" -period=768h -orphan -field=token 2>/dev/null)"

        if [ -z "$replacement" ]; then
            log "WARNING: could not issue a new application token; the current one stays in use"
            return 1
        fi

        write_secret "$APP_TOKEN_CURRENT" "$replacement" 600 || return 1
        # Revoked at the next check, not now: a request already holding the old one should finish.
        [ -n "$left" ] && { write_secret "$APP_TOKEN_PREVIOUS" "$current" 600 || return 1; }
        current="$replacement"
        log "issued a new application token"
    fi

    write_secret "$APP_TOKEN_PUBLISHED" "$current" 444 || return 1
}

if ! ensure_app_token; then
    log "FATAL: the application has no usable token."
    exit 1
fi

# The root token stays in this shell, for the daily check, and nowhere else: not exported, not in the
# server's environment (started above, before it was read), not in a log.
unset BAO_TOKEN UNSEAL_KEY INIT_FLAT

(
    while sleep "$CHECK_SECONDS"; do
        ensure_app_token || log "WARNING: the daily token check failed; trying again in ${CHECK_SECONDS}s"
    done
) &

log "ready; handing the foreground back to the server"
wait "$SERVER_PID"
