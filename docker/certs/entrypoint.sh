#!/bin/sh
# The stack's own certificate authority: issues every internal service its certificate, and renews it.
#
# ## Why one container, and not each service on its own
#
# There used to be two: `scripts/generate-certs.sh` wrote the edge a self-signed pair that lasted 825
# days and that nothing renewed, and OpenBao's startup script ran a private CA of its own. Two answers
# to one question, and only one of them renewed. Every service that speaks TLS inside the stack now
# gets its certificate from here, signed by one CA, and every caller verifies against that one CA.
#
# ## What it does, in order, and all of it idempotent
#
# 1. makes the CA on first start — its key stays in `/certs/ca`, a volume no other container mounts;
# 2. publishes the CA's certificate to `/certs/public/ca.crt`, which every caller mounts read-only;
# 3. issues each service listed in a `KERN_CERT_<name>` variable a key and certificate, into
#    `/certs/out/<name>` — that service's own volume, which only it mounts, so Postgres cannot read
#    Redis's key;
# 4. checks all of it again every `KERN_TLS_CHECK_SECONDS`, renewing whatever has
#    `KERN_TLS_RENEW_DAYS` or fewer left.
#
# It never restarts or signals anything: that needs the Docker socket, which is root on the host. Each
# service watches its own certificate file instead (`docker/tls/kern-tls.sh`) and reloads itself.
#
# ## The variables
#
#   KERN_CERT_<name>=<uid>:<gid>:<name>,<name>,…
#
# One per service, set by the compose file that runs that service, so leaving a service out of
# COMPOSE_FILE leaves its certificate out too. The uid and gid are the user the service runs as, which
# is who its key is handed to; the names are what callers connect to, DNS names or IP addresses.
#
# ## Renewing the CA
#
# It lives `KERN_TLS_CA_DAYS` and is renewed the same way. The published file then carries the new CA
# and the one before it, so a caller trusts both while every certificate is issued again under the new
# one — which happens in the same pass, because a certificate is reissued whenever it no longer
# verifies against the current CA. EC P-256 throughout: small, fast, and accepted by every TLS client
# in the stack.
set -eu

# Nothing this writes is anyone else's to read until it says so: every file that is meant to be read
# is given its mode explicitly, and a key is never readable even for the moment before that.
umask 077

CA_DIR=/certs/ca
PUBLIC_DIR=/certs/public
OUT_DIR=/certs/out
READY_FILE=/tmp/ready

CA_DAYS="${KERN_TLS_CA_DAYS:-3650}"
CERT_DAYS="${KERN_TLS_CERT_DAYS:-365}"
RENEW_DAYS="${KERN_TLS_RENEW_DAYS:-30}"
CHECK_SECONDS="${KERN_TLS_CHECK_SECONDS:-86400}"
CA_SUBJECT="${KERN_TLS_CA_SUBJECT:-/CN=kern internal CA}"

log() { echo "certs: $*"; }

# True when the certificate exists and has more than the renewal margin left.
cert_is_current() {
    [ -f "$1" ] && openssl x509 -checkend $((RENEW_DAYS * 86400)) -noout -in "$1" >/dev/null 2>&1
}

# A file written beside its destination is given its mode and owner, then moved into place: a reader
# never sees half a file, nor one readable by anyone it was not meant for.
install_file() {
    # $1 the file written, $2 its destination in the same directory, $3 mode, $4 owner (uid:gid)
    chmod "$3" "$1" && chown "$4" "$1" && mv "$1" "$2"
}

new_ec_key() {
    openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out "$1" 2>/dev/null
}

ensure_ca() {
    cert_is_current "${CA_DIR}/ca.crt" && [ -f "${CA_DIR}/ca.key" ] && return 0

    [ -f "${CA_DIR}/ca.crt" ] && { cp "${CA_DIR}/ca.crt" "${CA_DIR}/ca.previous.crt" || return 1; }
    new_ec_key "${CA_DIR}/ca.key.next" && chmod 600 "${CA_DIR}/ca.key.next" || return 1
    openssl req -x509 -new -key "${CA_DIR}/ca.key.next" -days "$CA_DAYS" -subj "$CA_SUBJECT" \
        -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign" \
        -out "${CA_DIR}/ca.crt.next" 2>/dev/null || return 1
    mv "${CA_DIR}/ca.key.next" "${CA_DIR}/ca.key" && mv "${CA_DIR}/ca.crt.next" "${CA_DIR}/ca.crt" || return 1
    log "issued a new certificate authority, valid ${CA_DAYS} days"
}

# The current CA, and the one before it while it is still valid.
ca_bundle() {
    cat "${CA_DIR}/ca.crt" || return 1
    if [ -f "${CA_DIR}/ca.previous.crt" ] && openssl x509 -checkend 0 -noout -in "${CA_DIR}/ca.previous.crt" >/dev/null 2>&1; then
        cat "${CA_DIR}/ca.previous.crt"
    fi
}

publish_ca() {
    ca_bundle > "${PUBLIC_DIR}/ca.crt.next" && install_file "${PUBLIC_DIR}/ca.crt.next" "${PUBLIC_DIR}/ca.crt" 644 0:0
}

# `a,b 10.0.0.1` -> `DNS:a,DNS:b,IP:10.0.0.1`. Commas and spaces both separate, because the edge's names
# come from the installer as a space-separated list of the host's addresses.
subject_alt_names() {
    san=""
    for entry in $(printf '%s' "$1" | tr ',' ' '); do
        case "$entry" in
            *:*|[0-9]*.[0-9]*.[0-9]*.[0-9]*) kind=IP ;;
            *) kind=DNS ;;
        esac
        san="${san:+${san},}${kind}:${entry}"
    done
    printf '%s' "$san"
}

# Issues `name` its key and certificate when it has none, when it is near expiry, when it was signed by
# a CA that is no longer current, or when the names it should carry changed.
ensure_leaf() {
    # $1 name, $2 uid:gid, $3 names
    dir="${OUT_DIR}/$1"

    if [ ! -d "$dir" ]; then
        log "ERROR: ${1} has no volume at ${dir} — its compose file must mount one there"
        return 1
    fi

    # The CA bundle beside the certificate, for the service's own healthcheck — refreshed every pass,
    # so a renewed CA reaches it before any certificate signed by the new one does.
    ca_bundle > "${dir}/ca.crt.next" && install_file "${dir}/ca.crt.next" "${dir}/ca.crt" 644 0:0 || return 1

    if cert_is_current "${dir}/server.crt" && [ -f "${dir}/server.key" ] \
        && [ "$(cat "${dir}/names" 2>/dev/null)" = "$3" ] \
        && openssl verify -CAfile "${CA_DIR}/ca.crt" "${dir}/server.crt" >/dev/null 2>&1; then
        return 0
    fi

    san="$(subject_alt_names "$3")"
    [ -n "$san" ] || { log "ERROR: ${1} lists no names"; return 1; }

    printf '%s\n' "subjectAltName=${san}" "basicConstraints=critical,CA:FALSE" \
        "keyUsage=critical,digitalSignature" "extendedKeyUsage=serverAuth" > /tmp/leaf.ext || return 1

    # Made in the service's own volume, under a temporary name, and moved into place: a rename within
    # one filesystem is atomic, and a copy from /tmp would need this container to read a key it has
    # already handed to another user — which it cannot, holding CAP_CHOWN and nothing else.
    new_ec_key "${dir}/server.key.next" || return 1
    openssl req -new -key "${dir}/server.key.next" -subj "/CN=${1}" -out /tmp/leaf.csr 2>/dev/null \
        && openssl x509 -req -in /tmp/leaf.csr -CA "${CA_DIR}/ca.crt" -CAkey "${CA_DIR}/ca.key" \
            -set_serial "0x$(openssl rand -hex 16)" -days "$CERT_DAYS" -extfile /tmp/leaf.ext \
            -out "${dir}/server.crt.next" 2>/dev/null || return 1

    # Key first, certificate last: services watch the certificate, so by the time it changes the key
    # beside it already matches. The key is the service's alone — 0600, owned by the user it runs as,
    # which is also the only mode Postgres accepts for a key it owns.
    install_file "${dir}/server.key.next" "${dir}/server.key" 600 "$2" || return 1
    install_file "${dir}/server.crt.next" "${dir}/server.crt" 644 "$2" || return 1
    printf '%s' "$3" > "${dir}/names" || return 1
    rm -f /tmp/leaf.csr /tmp/leaf.ext
    log "issued ${1} a certificate for ${san}, valid ${CERT_DAYS} days"
}

# Every `KERN_CERT_<name>=<uid>:<gid>:<names>` in the environment, as `<name> <uid>:<gid> <names>`.
leaves() {
    env | sed -n 's/^KERN_CERT_\([A-Za-z0-9_]*\)=\([0-9]*:[0-9]*\):\(.*\)$/\1 \2 \3/p'
}

# One line per certificate, `<name> <expiry>`, for `kern:health` — which can read this file, and not
# the certificates' keys beside them.
publish_status() {
    : > "${PUBLIC_DIR}/status.next" || return 1
    leaves | while read -r name owner names; do
        printf '%s %s\n' "$name" "$(openssl x509 -noout -enddate -dateopt iso_8601 -in "${OUT_DIR}/${name}/server.crt" | sed 's/^notAfter=//; s/ /T/')"
    done >> "${PUBLIC_DIR}/status.next" || return 1
    install_file "${PUBLIC_DIR}/status.next" "${PUBLIC_DIR}/status" 644 0:0
}

check_all() {
    ensure_ca && publish_ca || return 1

    failed=0
    # Not a pipe into `while`: that runs the loop in a subshell, and `failed` would never leave it.
    leaves > /tmp/leaves || return 1
    while read -r name owner names; do
        ensure_leaf "$name" "$owner" "$names" || failed=1
    done < /tmp/leaves

    [ "$failed" = 0 ] && publish_status
}

if [ -z "$(leaves)" ]; then
    log "WARNING: no KERN_CERT_* variables — there is nothing to issue"
fi

if ! check_all; then
    log "FATAL: could not issue every certificate on start — see above."
    exit 1
fi

touch "$READY_FILE"
log "ready; checking again every ${CHECK_SECONDS}s"

while sleep "$CHECK_SECONDS"; do
    check_all || log "WARNING: the check failed; the current certificates stay in use, trying again in ${CHECK_SECONDS}s"
done
