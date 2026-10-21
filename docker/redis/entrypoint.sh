#!/bin/sh
# Start redis-server with the password taken from the environment, on no command line at all.
#
# Not `--requirepass ${REDIS_PASSWORD}` in compose.yaml: that interpolation happens on the host and
# bakes the secret into the container's command, where `docker inspect` and the host process list
# show it in clear text. It also ties the password to the container's identity, so it only changes
# on recreate — and recreating `app` alone then leaves redis on the old password while the app has
# the new one. Every request fails with `WRONGPASS`, which looks like an application fault.
#
# Refuse to start rather than come up unprotected or with a placeholder: a Redis holding this
# application's sessions is a Redis that can be used to impersonate any signed-in user, and the
# empty-password failure mode is silent until someone finds the open port.
set -eu

if [ -z "${REDIS_PASSWORD:-}" ]; then
    echo "redis: REDIS_PASSWORD is empty — refusing to start without authentication." >&2
    exit 1
fi

case "${REDIS_PASSWORD}" in
    null|changeme|password|redis)
        echo "redis: REDIS_PASSWORD is a placeholder value — refusing to start." >&2
        exit 1
        ;;
esac

# Handed over as configuration on standard input (`-`), not as `--requirepass`. Any account on the host
# can read every process's command line with `ps`. Redis rewrites its own soon after starting, so the
# argument showed only for that first moment — but a moment it did, and a password does not belong on
# a command line for any length of time. A here-document is written to a pipe, never to argv.
#
# Inside the config the value is one double-quoted string, so a backslash or a quote in it is escaped
# first — `printf` is a shell builtin and `sed` reads it from that pipe, so neither puts it in argv.
ESCAPED_PASSWORD="$(printf '%s' "${REDIS_PASSWORD}" | sed 's/\\/\\\\/g; s/"/\\"/g')"

# A renewed certificate is read again with CONFIG SET, which Redis answers by loading it for every new
# connection, with no restart (docker/tls/kern-tls.sh watches the file). The password reaches
# redis-cli through REDISCLI_AUTH, never its command line; the watcher's own command line holds only
# the variable's name.
( sh /usr/local/bin/kern-tls watch "$$" 'exec:REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli --tls --cacert /run/kern/tls/ca.crt CONFIG SET tls-cert-file /run/kern/tls/server.crt tls-key-file /run/kern/tls/server.key' & )

# Through the image's own entrypoint, not `redis-server` directly: that is what drops from root to the
# image's `redis` user (setpriv, with every capability cleared) and hands it the data directory. Started
# directly, as this script used to, it skipped all of that and Redis ran as root for its whole life.
# Standard input passes through the drop, so the configuration below still arrives. `exec` all the way
# down, so redis-server becomes PID 1 and receives Docker's stop signals directly.
#
# TLS only: `port 0` closes the plain port, so a client that does not speak TLS is not answered at
# all. The certificate is issued by docker/certs; clients are not asked for one of their own
# (`tls-auth-clients no`) — the password is what identifies them.
exec /usr/local/bin/docker-entrypoint.sh redis-server - --appendonly yes "$@" <<CONFIG
requirepass "${ESCAPED_PASSWORD}"
port 0
tls-port 6379
tls-cert-file /run/kern/tls/server.crt
tls-key-file /run/kern/tls/server.key
tls-auth-clients no
CONFIG
