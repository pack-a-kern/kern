#!/bin/sh
# The installer's optional services — the processing plane and the browser worker — answered every
# way they can be: installed here, reached elsewhere, left out. Each answer is fed to the installer's
# own functions, and the environment file they write is read back.
#
# Never the real apps/api/.env: the installer is loaded with KERN_INSTALL_LIBRARY=yes, which stops it
# before it does anything, and ENV_FILE is pointed at a file in a directory made for this run.
#
# Usage: make test-installer

set -eu

ROOT="$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

FAILED=0

check() {
    if [ "$2" = "$3" ]; then
        printf '  ok    %s\n' "$1"
    else
        printf '  FAIL  %s: expected [%s], got [%s]\n' "$1" "$3" "$2"
        FAILED=1
    fi
}

# Runs one question with the given answers, writes its environment file, and prints that file.
#   $1 ask_processing or ask_browser_worker  $2 the answers, one per line
answer() {
    env_file="$WORK/env"
    printf 'PROCESSING_URL=https://python-api:8000\nBROWSER_WORKER_URL=https://browser-worker:8090\n' > "$env_file"

    # The file by name in the environment, not as an argument: the installer reads its arguments as
    # options and refuses any it does not know.
    printf '%s' "$2" | KERN_INSTALL_LIBRARY=yes TEST_ENV_FILE="$env_file" sh -c '
        . "$0"
        ENV_FILE="$TEST_ENV_FILE"
        TRUSTED_DIR="$(dirname "$TEST_ENV_FILE")/trusted"
        '"$1"' >/dev/null
        write_processing
        write_optional_service "$BROWSER_MODE" BROWSER_WORKER_URL BROWSER_WORKER_CA_CERT BROWSER_WORKER_TOKEN \
            "$BROWSER_URL_ANSWER" "$BROWSER_CA" "$BROWSER_TOKEN"
    ' "$ROOT/scripts/install.sh"

    cat "$env_file"
}

value() {
    printf '%s\n' "$1" | awk -F'=' -v key="$2" '$1 == key { sub(/^[^=]*=/, ""); print; found = 1 } END { if (!found) print "<unset>" }'
}

for service in processing browser; do
    if [ "$service" = processing ]; then
        ask=ask_processing url=PROCESSING_URL ca=PROCESSING_CA_CERT token=INTERNAL_SERVICE_AUTH_TOKEN
        default_url=https://python-api:8000
        # Asked after the plane, here and elsewhere: by meaning when installed here, by words when elsewhere.
        here='1
' elsewhere='2
'
    else
        ask=ask_browser_worker url=BROWSER_WORKER_URL ca=BROWSER_WORKER_CA_CERT token=BROWSER_WORKER_TOKEN
        default_url=https://browser-worker:8090
        here='' elsewhere=''
    fi

    printf '\n%s, installed here\n' "$service"
    out=$(answer "$ask" "1
$here")
    check 'a generated token of 64 hex digits' "$(value "$out" "$token" | grep -cE '^[0-9a-f]{64}$')" 1
    check 'the address left at the compose default' "$(value "$out" "$url")" "$default_url"
    [ "$service" = browser ] || check 'the laws searched by meaning' "$(value "$out" RETRIEVAL_EMBEDDING_ENABLED)" true

    printf '\n%s, reached elsewhere (an http:// address refused first)\n' "$service"
    out=$(answer "$ask" "2
http://elsewhere.example
https://elsewhere.example

the-shared-secret
$elsewhere")
    check 'the https:// address' "$(value "$out" "$url")" https://elsewhere.example
    check 'no private CA' "$(value "$out" "$ca")" ''
    check 'the token as typed' "$(value "$out" "$token")" the-shared-secret
    [ "$service" = browser ] || check 'the laws searched by words alone' "$(value "$out" RETRIEVAL_EMBEDDING_ENABLED)" false

    printf '\n%s, left out\n' "$service"
    out=$(answer "$ask" '3
')
    check 'the address written empty' "$(value "$out" "$url")" ''
    check 'no token' "$(value "$out" "$token")" '<unset>'
    [ "$service" = browser ] || check 'no plane, so by words alone' "$(value "$out" RETRIEVAL_EMBEDDING_ENABLED)" false
done

[ "$FAILED" = 0 ] || { printf '\nThe installer answered wrongly.\n'; exit 1; }
printf '\nAll answers written as expected.\n'
