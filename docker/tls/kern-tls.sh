#!/bin/sh
# Starts a service, and makes it pick up a renewed certificate without anyone's help.
#
#   kern-tls run <reload> -- <command> [args…]    start the watcher, then become <command>
#   kern-tls watch <pid> <reload>                 the watcher alone, for a script that starts its own server
#
# <reload> is what a changed certificate means for this service:
#
#   HUP, USR1, …     a signal to the server, for one that reloads its certificate in place
#   TERM             the same, for one that cannot: it exits, and `restart: unless-stopped` starts it
#                    again with the new certificate
#   exec:<command>   a command, for one that is told some other way (Redis: CONFIG SET)
#
# `docker/certs` renews certificates and never restarts or signals anything — that would take the
# Docker socket, which is root on the host. Each service watches its own file instead, every
# `KERN_TLS_WATCH_SECONDS`, and compares a checksum, so a certificate written again unchanged does not
# count.
#
# The watcher is detached from the server it watches (`init: true` in compose reaps it), so it is never
# a child the server has to account for: Postgres treats an unknown child that exits as a crashed
# backend. `exec` keeps the server's own process id, which is the one the watcher signals.
set -eu

CERT="${KERN_TLS_CERT:-/run/kern/tls/server.crt}"
INTERVAL="${KERN_TLS_WATCH_SECONDS:-300}"

log() { echo "kern-tls: $*" >&2; }

fingerprint() {
    cksum < "$CERT" 2>/dev/null || echo missing
}

watch() {
    # $1 the server's pid, $2 the reload
    seen="$(fingerprint)"

    # /proc rather than `kill -0`: the server may run as another user than this watcher, and asking
    # whether it is alive must not need the permission to signal it.
    while [ -d "/proc/$1" ]; do
        sleep "$INTERVAL"
        now="$(fingerprint)"
        [ "$now" = "$seen" ] && continue
        seen="$now"

        case "$2" in
            exec:*)
                if sh -c "${2#exec:}" >/dev/null; then
                    log "certificate changed; reloaded"
                else
                    log "WARNING: certificate changed and the reload failed; trying again at the next change"
                fi
                ;;
            *)
                log "certificate changed; sending SIG${2}"
                kill -s "$2" "$1" || log "WARNING: could not signal ${1}"
                ;;
        esac
    done
}

case "${1:-}" in
    run)
        [ "$#" -ge 4 ] && [ "$3" = "--" ] || { log "usage: kern-tls run <reload> -- <command…>"; exit 64; }
        reload="$2"
        shift 3
        # $$ is this shell, and stays the server's pid after the `exec` below. The subshell exits at once,
        # so the watcher is re-parented to the container's init.
        ( watch "$$" "$reload" & )
        exec "$@"
        ;;
    watch)
        [ "$#" -eq 3 ] || { log "usage: kern-tls watch <pid> <reload>"; exit 64; }
        watch "$2" "$3"
        ;;
    *)
        log "usage: kern-tls run <reload> -- <command…> | kern-tls watch <pid> <reload>"
        exit 64
        ;;
esac
