#!/bin/sh
# Start redis-server with the password taken from the environment.
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

# `exec` so redis-server becomes PID 1 and receives Docker's stop signals directly.
exec redis-server --requirepass "${REDIS_PASSWORD}" --appendonly yes "$@"
