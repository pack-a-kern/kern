#!/bin/sh
# Kern — from a fresh clone to a working installation, in one command.
#
#     make install
#
# ## What it replaces
#
# `docs/DEPLOYMENT.md` §4–5 asks an operator to generate nine secrets by hand, fill in roughly forty
# values, make a TLS certificate, build assets, build images, start the stack, run migrations, create
# the role catalogue, create the first administrator and then verify all of it — each step its own
# copied command, in an order nothing enforces. Every one of those is below, in that order, with the
# secrets generated rather than typed.
#
# ## Why a shell script and not an artisan command
#
# At step one there is no `apps/api/.env`, no `vendor/`, and no built image — so there is no PHP to
# run. The outer driver has to be a shell script. It calls artisan for everything that needs the
# framework, once the image exists: `migrate`, `kern:access:sync`, `kern:admin:create`, `kern:health`.
#
# ## The shape it installs
#
# A production shape: `APP_ENV=production` out of `apps/api/.env.example`, and a `COMPOSE_FILE` with
# no `.dev.yaml` in it. A developer wanting the dev conveniences uses `make env`, which brings the
# full list from `.env.example` — that file is the development default, this script is the server one.
#
# ## Secrets
#
# Every generated value comes from `openssl rand` and goes straight into `apps/api/.env` at mode
# 0600. None is printed, and none is passed as a command-line argument — arguments are visible in
# `ps`, in the shell's history and in `docker inspect`. The administrator's password is held in a
# shell variable for the length of the run (process memory, never a file) and reaches artisan on
# standard input, which is what `kern:admin:create --stdin` exists for.
#
# ## Re-running it
#
# It refuses to overwrite an `apps/api/.env` that already exists, the way `make env` does: that file
# holds the only copy of this installation's secrets. `--repair` is the way back in — it asks
# nothing, writes nothing, and re-runs the steps after the environment file (start, wait, migrate,
# sync, health) against the installation that is already there.

set -eu

ROOT="$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
cd "$ROOT"

ENV_FILE="apps/api/.env"
ENV_MODE=600

# Memory limits, from the compose files that set them: compose.yaml (edge 128, app 512,
# queue 2048 by QUEUE_MEMORY_LIMIT, scheduler 128, reverb 256) and one per optional service. Summed
# for the summary, so the figure an operator is shown is the figure the stack is actually limited
# to, not a guess.
RAM_ALWAYS_MB=3072
RAM_POSTGRES_MB=1024
RAM_REDIS_MB=256
RAM_OPENBAO_MB=256
RAM_CLAMAV_MB=1536
RAM_PYTHON_MB=1024
RAM_BROWSER_MB=2048

# Disk, measured on this project's own built images rather than estimated. The four PHP containers
# share one image's layers, so they count once.
DISK_ALWAYS_GB=2
DISK_POSTGRES_GB=1
DISK_REDIS_GB=1
DISK_OPENBAO_GB=1
DISK_CLAMAV_GB=2
DISK_PYTHON_GB=2
DISK_BROWSER_GB=4

REPAIR=no

for argument in "$@"; do
    case "$argument" in
        --repair) REPAIR=yes ;;
        *)
            echo "Unknown option [$argument]. The only one is --repair." >&2
            exit 1
            ;;
    esac
done

# ---------------------------------------------------------------------------
# Terminal
# ---------------------------------------------------------------------------

die() {
    printf '\n%s\n' "$*" >&2
    exit 1
}

heading() {
    printf '\n%s\n' "$1"
}

step() {
    printf '\n── %s\n' "$1"
}

# A prompt's answer, in a global: a function cannot return a string in POSIX shell, and the
# alternative — a subshell printing it — loses every variable the questions set.
ANSWER=''

ask() {
    if [ -n "${2:-}" ]; then
        printf '  %s [%s]: ' "$1" "$2"
    else
        printf '  %s: ' "$1"
    fi

    IFS= read -r ANSWER || die 'Standard input ended. This script asks questions, so it needs a terminal.'

    [ -n "$ANSWER" ] || ANSWER="${2:-}"
}

ask_required() {
    while :; do
        ask "$1" "${2:-}"
        [ -z "$ANSWER" ] || return 0
        echo '  That one has no default and the installation needs it.'
    done
}

# Echo is turned off for the answer and turned back on by the trap below, so an interrupt in the
# middle of a password prompt does not leave the terminal silent.
trap 'stty echo 2>/dev/null || true' EXIT INT TERM

ask_secret() {
    printf '  %s: ' "$1"
    stty -echo 2>/dev/null || true
    IFS= read -r ANSWER || ANSWER=''
    stty echo 2>/dev/null || true
    printf '\n'
}

CHOICE=''

# A numbered menu. `choose "<question>" <default> "<option>" "<option>" …`
choose() {
    question="$1"
    default="$2"
    shift 2

    printf '\n%s\n' "$question"

    index=1
    for option in "$@"; do
        printf '  %d) %s\n' "$index" "$option"
        index=$((index + 1))
    done

    last=$#

    while :; do
        printf 'Choice [%s]: ' "$default"
        IFS= read -r CHOICE || die 'Standard input ended. This script asks questions, so it needs a terminal.'
        [ -n "$CHOICE" ] || CHOICE="$default"

        case "$CHOICE" in
            *[!0-9]* | '') ;;
            *) [ "$CHOICE" -ge 1 ] && [ "$CHOICE" -le "$last" ] && return 0 ;;
        esac

        printf 'Answer with a number between 1 and %d.\n' "$last"
    done
}

confirm() {
    printf '\n%s [y/N]: ' "$1"
    IFS= read -r reply || reply=''

    case "$reply" in
        y | Y | yes | YES) return 0 ;;
        *) return 1 ;;
    esac
}

# ---------------------------------------------------------------------------
# The environment file
# ---------------------------------------------------------------------------

# Set one variable in apps/api/.env, keeping the sentence above it that explains what it is.
#
# In place where the key is already there — which is every key this script writes except the two
# `KERN_EDGE_*`, which `.env.example` ships commented out because unset is their working default. A
# commented line with exactly one space (`# KEY=`) is that placeholder and is replaced; the
# indented examples in the prose above it are not, so the documentation survives.
#
# The value arrives through the environment rather than as an awk `-v`, which would read a
# backslash in a password as an escape, and is never interpolated into a pattern.
set_env() {
    KERN_ENV_VALUE="$2" awk -v key="$1" '
        BEGIN { written = 0 }
        !written && (index($0, key "=") == 1 || index($0, "# " key "=") == 1) {
            print key "=" ENVIRON["KERN_ENV_VALUE"]
            written = 1
            next
        }
        { print }
        END { if (!written) print key "=" ENVIRON["KERN_ENV_VALUE"] }
    ' "$ENV_FILE" > "$ENV_FILE.tmp"

    mv "$ENV_FILE.tmp" "$ENV_FILE"
    chmod "$ENV_MODE" "$ENV_FILE"
}

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------

require_command() {
    command -v "$1" >/dev/null 2>&1 || die "$1 is not installed, and $2"
}

preflight() {
    heading 'Checking what this machine has'

    require_command docker 'nothing in this stack runs without it.'
    require_command openssl 'it is what generates every secret below.'

    # The edge image copies apps/api/public at build time and runs no JavaScript build tool itself
    # (docker/caddy/Dockerfile), so the assets are built here, on the host, before any image is.
    require_command npm 'the frontend assets are built before the images, and nothing else builds them.'

    docker compose version >/dev/null 2>&1 \
        || die 'docker compose is not available. This needs Compose v2 — `docker compose version` must answer.'

    docker info >/dev/null 2>&1 \
        || die 'The docker daemon is not answering. Start it, or add this user to the docker group.'

    printf '  docker, docker compose, openssl, npm   all present\n'

    if [ -r /proc/meminfo ]; then
        RAM_TOTAL_MB=$(awk '/^MemTotal:/ { printf "%d", $2 / 1024 }' /proc/meminfo)
        printf '  memory                                 %s MB\n' "$RAM_TOTAL_MB"
    else
        RAM_TOTAL_MB=0
        printf '  memory                                 could not measure it; the summary will not check it\n'
    fi

    DISK_FREE_GB=$(df -P -k . | awk 'NR == 2 { printf "%d", $4 / 1048576 }')
    printf '  free disk here                         %s GB\n' "$DISK_FREE_GB"
}

# ---------------------------------------------------------------------------
# The questions
# ---------------------------------------------------------------------------

# Answers. Declared here so every one of them is in one place, and so `set -u` catches a path that
# reads one the questions never set.
SITE_HOST=''
SITE_URL=''
EDGE_SITE=''
EDGE_TLS=''
EDGE_ACME_DNS=''
EDGE_DNS_TOKEN=''
TLS_NOTE=''

DB_LOCAL=yes
DB_HOST=postgres
DB_PORT=5432
DB_NAME=kern
DB_USER=kern
DB_PASS=''

REDIS_LOCAL=yes
REDIS_HOST_ANSWER=redis
REDIS_PORT_ANSWER=6379
REDIS_PASS=''

VAULT_LOCAL=yes
VAULT_ADDR='http://openbao:8200'
VAULT_TOKEN=''

CLAMAV_LOCAL=yes
CLAMAV_HOST_ANSWER=clamav
CLAMAV_PORT_ANSWER=3310

STORAGE_ENDPOINT=''
STORAGE_KEY=''
STORAGE_SECRET=''
STORAGE_BUCKET=kern-documents
STORAGE_QUARANTINE=kern-quarantine

PROCESSING_MODE=local
PROCESSING_URL_ANSWER='http://python-api:8000'
PROCESSING_TOKEN=''

BROWSER_MODE=local
BROWSER_URL_ANSWER='http://browser-worker:8090'
BROWSER_TOKEN=''

MAIL_MODE=log
MAIL_HOST_ANSWER=''
MAIL_PORT_ANSWER=587
MAIL_USER_ANSWER=''
MAIL_PASS_ANSWER=''
MAIL_FROM_ANSWER=''

ADMIN_EMAIL=''
ADMIN_PASSWORD=''

ask_how_it_is_reached() {
    DEFAULT_ADDRESS=$(scripts/host-addresses.sh 2>/dev/null | head -n 1 || true)

    choose 'How will this installation be reached?' 1 \
        'An IP address — self-signed TLS, and browsers will warn' \
        "A domain name, with ports 80 and 443 reachable from the internet — Let's Encrypt, renewed by the edge" \
        "A domain name behind Cloudflare's proxy — Let's Encrypt proved through DNS, renewed by the edge" \
        'A domain name, with a certificate you already have — including a Cloudflare Origin certificate'

    case "$CHOICE" in
        1)
            ask_required 'The address people will type' "$DEFAULT_ADDRESS"
            SITE_HOST="$ANSWER"
            # Both KERN_EDGE_* stay unset: that is the Caddyfile's own default — every address this
            # machine has, presented with the self-signed pair generated below. There is no name in
            # `:8443` for a public certificate authority to issue a certificate for, and Let's
            # Encrypt's IP certificates are not confirmed working against the Caddy version this
            # repository pins (docs/INSTALLER-PLAN.md §8), so this answer does not pretend otherwise.
            TLS_NOTE='self-signed — a browser will warn, which is the honest state of a server reached by IP'
            ;;
        2)
            ask_required 'The domain name' ''
            SITE_HOST="$ANSWER"
            EDGE_SITE="$SITE_HOST"
            ask_required 'An email address for the certificate authority' ''
            EDGE_TLS="$ANSWER"
            TLS_NOTE="Let's Encrypt, obtained on the first request to $SITE_HOST and renewed by the edge"
            ;;
        3)
            ask_required 'The domain name' ''
            SITE_HOST="$ANSWER"
            EDGE_SITE="$SITE_HOST"
            ask_required 'An email address for the certificate authority' ''
            EDGE_TLS="$ANSWER"
            # Cloudflare terminates TLS at its own edge, so a challenge answered on port 80 or 443
            # never reaches this server and both default methods fail for a name that is otherwise
            # configured correctly. Proving the name through DNS instead works regardless of the
            # proxy — Caddy writes a record in the zone and the authority reads it.
            #
            # The directive is written whole rather than as a provider name, because a Caddyfile has
            # no conditionals and `acme_dns` with no provider is a configuration error; the token
            # goes in its own variable so Caddy reads it at runtime instead of it living in the
            # configuration's text. Cloudflare is the one provider compiled into the edge image.
            printf '\n  The token is made at Cloudflare → My Profile → API Tokens, scoped to\n'
            printf '  Zone:DNS:Edit and Zone:Zone:Read on this one zone. Never a global API key.\n'
            ask_secret 'Cloudflare API token'
            EDGE_DNS_TOKEN="$ANSWER"
            EDGE_ACME_DNS='acme_dns cloudflare {env.KERN_EDGE_DNS_TOKEN}'
            TLS_NOTE="Let's Encrypt, proved through Cloudflare's DNS and renewed by the edge"
            ;;
        4)
            ask_required 'The domain name' ''
            SITE_HOST="$ANSWER"
            EDGE_SITE="$SITE_HOST"
            # The path inside the container, which is where compose mounts docker/caddy/certs. The
            # self-signed pair generated below lands there, so this answer starts working
            # immediately and the operator replaces the two files with theirs — a Cloudflare Origin
            # certificate is valid for fifteen years and needs no renewal, which is why it is worth
            # offering beside ACME rather than instead of it.
            EDGE_TLS='/etc/caddy/certs/kern.crt /etc/caddy/certs/kern.key'
            TLS_NOTE='your own certificate at docker/caddy/certs/kern.crt and kern.key'
            ;;
    esac
}

ask_database() {
    choose 'Database' 1 \
        'Install PostgreSQL here' \
        'Use a PostgreSQL I already run'

    if [ "$CHOICE" = 1 ]; then
        DB_LOCAL=yes

        return 0
    fi

    DB_LOCAL=no
    ask_required 'Host' ''
    DB_HOST="$ANSWER"
    ask 'Port' 5432
    DB_PORT="$ANSWER"
    ask 'Database name' kern
    DB_NAME="$ANSWER"
    ask 'User' kern
    DB_USER="$ANSWER"
    ask_secret 'Password'
    DB_PASS="$ANSWER"
}

ask_cache() {
    choose 'Cache, sessions and queues (Redis)' 1 \
        'Install Redis here' \
        'Use a Redis I already run'

    if [ "$CHOICE" = 1 ]; then
        REDIS_LOCAL=yes

        return 0
    fi

    REDIS_LOCAL=no
    ask_required 'Host' ''
    REDIS_HOST_ANSWER="$ANSWER"
    ask 'Port' 6379
    REDIS_PORT_ANSWER="$ANSWER"
    ask_secret 'Password (empty if it has none)'
    REDIS_PASS="$ANSWER"
}

ask_vault() {
    choose 'Key vault (OpenBao) — it holds the master key every encrypted field depends on' 1 \
        'Install OpenBao here' \
        'Use an OpenBao or Vault I already run'

    if [ "$CHOICE" = 1 ]; then
        VAULT_LOCAL=yes

        return 0
    fi

    VAULT_LOCAL=no
    ask_required 'Address' 'http://10.73.4.4:8200'
    VAULT_ADDR="$ANSWER"
    # Not generated: an external vault's token is issued by that vault. It must already allow
    # encrypt, decrypt and a read of the key's metadata on the transit mount — `kern:health` at the
    # end of this run is what proves it does, and the install fails there if it does not.
    ask_secret 'A token with encrypt, decrypt and read on the transit key'
    VAULT_TOKEN="$ANSWER"
}

ask_antivirus() {
    choose 'Virus scanner (ClamAV) — required, because an upload that cannot be scanned is refused' 1 \
        'Install ClamAV here (1.5 GB of memory, and it downloads its signatures on first start)' \
        'Use a clamd I already run'

    if [ "$CHOICE" = 1 ]; then
        CLAMAV_LOCAL=yes

        return 0
    fi

    CLAMAV_LOCAL=no
    ask_required 'Host' ''
    CLAMAV_HOST_ANSWER="$ANSWER"
    ask 'Port' 3310
    CLAMAV_PORT_ANSWER="$ANSWER"
}

ask_object_storage() {
    # No "install it here" answer, because there is no `compose.services/minio.yaml` to include —
    # this repository has never run object storage locally, and offering a choice that resolves to
    # a file that does not exist would be an installer that fails after the questions.
    heading 'Object storage — where documents are kept (S3, MinIO, or anything that speaks S3)'
    printf '  There is no option to install this here: this repository ships no object-storage\n'
    printf '  service, so it is an address you already have.\n\n'

    ask_required 'Endpoint' 'http://10.73.4.3:9000'
    STORAGE_ENDPOINT="$ANSWER"
    ask_required 'Access key' ''
    STORAGE_KEY="$ANSWER"
    ask_secret 'Secret key'
    STORAGE_SECRET="$ANSWER"
    ask 'Bucket for documents' kern-documents
    STORAGE_BUCKET="$ANSWER"
    ask 'Bucket for uploads awaiting their virus scan' kern-quarantine
    STORAGE_QUARANTINE="$ANSWER"
}

ask_processing() {
    choose 'Document AI — reads PDFs and bank statements' 1 \
        'Install the processing plane here (1 GB of memory)' \
        'Use a processing plane I already run' \
        'Skip it — no AI features'

    case "$CHOICE" in
        1)
            PROCESSING_MODE=local
            PROCESSING_TOKEN=$(openssl rand -hex 32)
            ;;
        2)
            PROCESSING_MODE=remote
            ask_required 'Address' ''
            PROCESSING_URL_ANSWER="$ANSWER"
            # Asked, not generated: both ends present the same secret, and the other end already
            # has its copy.
            ask_secret 'The shared secret that processing plane expects'
            PROCESSING_TOKEN="$ANSWER"
            ;;
        3) PROCESSING_MODE=skip ;;
    esac
}

ask_browser_worker() {
    choose 'SPV automation — the browser robot that signs in to ANAF' 1 \
        'Install the browser worker here (2 GB of memory, 3.6 GB of disk)' \
        'Use a browser worker I already run' \
        'Skip it — no SPV automation'

    case "$CHOICE" in
        1)
            # No token generated. A locally installed worker sits alone on `browser_net` with
            # nothing else able to reach its port, which is what `BROWSER_AUTH_MODE=network` — the
            # mode compose.services/browser.yaml sets — means. A token in the environment that
            # nothing checks would read like protection that is not there.
            BROWSER_MODE=local
            ;;
        2)
            BROWSER_MODE=remote
            ask_required 'Address' ''
            BROWSER_URL_ANSWER="$ANSWER"
            ask_secret 'The token that worker expects'
            BROWSER_TOKEN="$ANSWER"
            ;;
        3) BROWSER_MODE=skip ;;
    esac
}

ask_mail() {
    choose 'Email — password resets, verification codes, notifications' 1 \
        'An SMTP server' \
        'Skip it — mail is written to the application log instead'

    if [ "$CHOICE" = 2 ]; then
        MAIL_MODE=log

        return 0
    fi

    MAIL_MODE=smtp
    ask_required 'SMTP host' ''
    MAIL_HOST_ANSWER="$ANSWER"
    ask 'Port' 587
    MAIL_PORT_ANSWER="$ANSWER"
    ask 'User (empty if it needs none)' ''
    MAIL_USER_ANSWER="$ANSWER"
    ask_secret 'Password (empty if it needs none)'
    MAIL_PASS_ANSWER="$ANSWER"
    # A default only when the site is a domain name. `kern@<an IP address>` is not an address any
    # mail server will accept, and offering it as the obvious answer is how it ends up being used.
    if [ -n "$EDGE_SITE" ]; then
        ask_required 'The address mail is sent from' "kern@$EDGE_SITE"
    else
        ask_required 'The address mail is sent from' ''
    fi

    MAIL_FROM_ANSWER="$ANSWER"
}

ask_administrator() {
    heading 'The first administrator'

    ask_required 'Email' ''
    ADMIN_EMAIL="$ANSWER"

    while :; do
        ask_secret 'Password (at least 12 characters)'
        first="$ANSWER"
        ask_secret 'The same password again'

        if [ "$first" != "$ANSWER" ]; then
            echo '  Those are not the same. Again:'

            continue
        fi

        # The same floor `kern:admin:create` enforces, checked here so it is caught at the prompt
        # rather than four minutes later, after the images are built.
        if [ "${#first}" -lt 12 ]; then
            echo '  Too short — at least 12 characters. Again:'

            continue
        fi

        ADMIN_PASSWORD="$first"
        break
    done
}

# ---------------------------------------------------------------------------
# What was chosen, before anything is written
# ---------------------------------------------------------------------------

COMPOSE_LIST='compose.yaml'
SERVICE_NAMES='edge app queue scheduler reverb'
RAM_NEEDED_MB="$RAM_ALWAYS_MB"
DISK_NEEDED_GB="$DISK_ALWAYS_GB"

include_service() {
    COMPOSE_LIST="$COMPOSE_LIST:compose.services/$1.yaml"
    SERVICE_NAMES="$SERVICE_NAMES $2"
    RAM_NEEDED_MB=$((RAM_NEEDED_MB + $3))
    DISK_NEEDED_GB=$((DISK_NEEDED_GB + $4))
}

plan_services() {
    [ "$DB_LOCAL" = no ] || include_service postgres postgres "$RAM_POSTGRES_MB" "$DISK_POSTGRES_GB"
    [ "$REDIS_LOCAL" = no ] || include_service redis redis "$RAM_REDIS_MB" "$DISK_REDIS_GB"
    [ "$VAULT_LOCAL" = no ] || include_service openbao openbao "$RAM_OPENBAO_MB" "$DISK_OPENBAO_GB"
    [ "$CLAMAV_LOCAL" = no ] || include_service clamav clamav "$RAM_CLAMAV_MB" "$DISK_CLAMAV_GB"
    [ "$PROCESSING_MODE" != local ] || include_service python python-api "$RAM_PYTHON_MB" "$DISK_PYTHON_GB"
    [ "$BROWSER_MODE" != local ] || include_service browser browser-worker "$RAM_BROWSER_MB" "$DISK_BROWSER_GB"
}

print_summary() {
    heading 'This is what will be installed'

    printf '  address        https://%s\n' "$SITE_HOST"
    printf '  TLS            %s\n' "$TLS_NOTE"
    printf '  containers     %s\n' "$SERVICE_NAMES"
    printf '  memory         about %s MB of limits, against %s MB on this machine\n' "$RAM_NEEDED_MB" "$RAM_TOTAL_MB"
    printf '  disk           about %s GB, against %s GB free here\n' "$DISK_NEEDED_GB" "$DISK_FREE_GB"

    printf '\n  elsewhere:\n'
    printf '    object storage   %s\n' "$STORAGE_ENDPOINT"
    [ "$DB_LOCAL" = yes ] || printf '    database         %s:%s\n' "$DB_HOST" "$DB_PORT"
    [ "$REDIS_LOCAL" = yes ] || printf '    redis            %s:%s\n' "$REDIS_HOST_ANSWER" "$REDIS_PORT_ANSWER"
    [ "$VAULT_LOCAL" = yes ] || printf '    key vault        %s\n' "$VAULT_ADDR"
    [ "$CLAMAV_LOCAL" = yes ] || printf '    virus scanner    %s:%s\n' "$CLAMAV_HOST_ANSWER" "$CLAMAV_PORT_ANSWER"
    [ "$PROCESSING_MODE" != remote ] || printf '    document AI      %s\n' "$PROCESSING_URL_ANSWER"
    [ "$BROWSER_MODE" != remote ] || printf '    SPV automation   %s\n' "$BROWSER_URL_ANSWER"
    [ "$MAIL_MODE" != smtp ] || printf '    email            %s:%s\n' "$MAIL_HOST_ANSWER" "$MAIL_PORT_ANSWER"

    if [ "$PROCESSING_MODE" = skip ] || [ "$BROWSER_MODE" = skip ] || [ "$MAIL_MODE" = log ]; then
        printf '\n  left out:\n'
        [ "$PROCESSING_MODE" != skip ] || printf '    document AI      no AI features\n'
        [ "$BROWSER_MODE" != skip ] || printf '    SPV automation   no automated ANAF sign-in\n'
        [ "$MAIL_MODE" != log ] || printf '    email            written to the application log\n'
    fi

    if [ "$RAM_TOTAL_MB" -gt 0 ] && [ "$RAM_NEEDED_MB" -gt "$RAM_TOTAL_MB" ]; then
        printf '\n  The limits above add up to more memory than this machine has. The containers will\n'
        printf '  start, and the kernel will kill whichever one asks for memory first.\n'
    fi

    if [ "$DISK_NEEDED_GB" -gt "$DISK_FREE_GB" ]; then
        printf '\n  There is less free disk here than the images need. The build will stop part way.\n'
    fi
}

# ---------------------------------------------------------------------------
# Writing the environment file
# ---------------------------------------------------------------------------

write_environment_file() {
    # The application's `.env.example` followed by the one every installed package ships, which is
    # the whole environment with the sentence that explains each variable intact. `cat`, not
    # `php artisan kern:env`, which does the same thing properly but needs composer to have run and
    # a container to run in — neither exists yet. The command is the one to use afterwards, after
    # installing a package, to see the variables it added.
    cat apps/api/.env.example packages/*/.env.example > "$ENV_FILE"
    chmod "$ENV_MODE" "$ENV_FILE"

    # Generated, never typed, never printed. `openssl rand -hex` rather than base64 for anything
    # compose interpolates: a `$` in a password makes compose read the rest of it as another
    # variable's name.
    set_env APP_KEY "base64:$(openssl rand -base64 32)"
    set_env CRYPTO_BLIND_INDEX_KEYS "{\"1\":\"$(openssl rand -base64 32)\"}"
    set_env REVERB_APP_ID "$(openssl rand -hex 16)"

    REVERB_KEY=$(openssl rand -hex 20)
    set_env REVERB_APP_KEY "$REVERB_KEY"
    set_env REVERB_APP_SECRET "$(openssl rand -hex 20)"
    # The browser's copy of the same key, baked into the assets when they are built — which is why
    # this has to be written before the build below and not after.
    set_env VITE_REVERB_APP_KEY "$REVERB_KEY"

    SITE_URL="https://$SITE_HOST"
    set_env APP_URL "$SITE_URL"
    # Reverb compares the page's host only. Unset it defaults to APP_URL's host and the failure is
    # quiet: the socket connects and then delivers nothing. localhost is kept for `make smoke`.
    set_env REVERB_ALLOWED_ORIGINS "$SITE_HOST,localhost"

    # Only when there is something to say. `.env.example` ships both commented out because unset is
    # their working default — the Caddyfile then answers on every address with the self-signed pair —
    # and an empty `KERN_EDGE_TLS=` is not the same thing as an absent one: compose forwards a
    # variable that is set to nothing, and `tls` with no argument is a configuration error that
    # stops the edge from starting at all.
    if [ -n "$EDGE_SITE" ]; then
        set_env KERN_EDGE_SITE "$EDGE_SITE"
        set_env KERN_EDGE_TLS "$EDGE_TLS"
    fi

    # Same reasoning again, and it is the reason the directive is a variable at all: written empty
    # it is an `acme_dns` with no provider, which stops the edge from starting.
    if [ -n "$EDGE_ACME_DNS" ]; then
        set_env KERN_EDGE_ACME_DNS "$EDGE_ACME_DNS"
        set_env KERN_EDGE_DNS_TOKEN "$EDGE_DNS_TOKEN"
    fi

    if [ "$DB_LOCAL" = yes ]; then
        set_env DB_PASSWORD "$(openssl rand -hex 24)"
    else
        set_env DB_HOST "$DB_HOST"
        set_env DB_PORT "$DB_PORT"
        set_env DB_DATABASE "$DB_NAME"
        set_env DB_USERNAME "$DB_USER"
        set_env DB_PASSWORD "$DB_PASS"
    fi

    if [ "$REDIS_LOCAL" = yes ]; then
        set_env REDIS_PASSWORD "$(openssl rand -hex 24)"
    else
        set_env REDIS_HOST "$REDIS_HOST_ANSWER"
        set_env REDIS_PORT "$REDIS_PORT_ANSWER"
        set_env REDIS_PASSWORD "$REDIS_PASS"
    fi

    if [ "$VAULT_LOCAL" = yes ]; then
        # The id of the token the vault's own entrypoint then issues against a policy covering the
        # four transit paths the application calls. Generated here because the container is created
        # with it, so both halves read the same value from this file.
        set_env OPENBAO_TOKEN "$(openssl rand -hex 32)"
    else
        set_env OPENBAO_ADDR "$VAULT_ADDR"
        set_env OPENBAO_TOKEN "$VAULT_TOKEN"
    fi

    if [ "$CLAMAV_LOCAL" = no ]; then
        set_env CLAMAV_HOST "$CLAMAV_HOST_ANSWER"
        set_env CLAMAV_PORT "$CLAMAV_PORT_ANSWER"
    fi

    set_env AWS_ENDPOINT "$STORAGE_ENDPOINT"
    set_env AWS_ACCESS_KEY_ID "$STORAGE_KEY"
    set_env AWS_SECRET_ACCESS_KEY "$STORAGE_SECRET"
    set_env AWS_BUCKET "$STORAGE_BUCKET"
    set_env QUARANTINE_BUCKET "$STORAGE_QUARANTINE"

    case "$PROCESSING_MODE" in
        local)
            set_env INTERNAL_SERVICE_AUTH_TOKEN "$PROCESSING_TOKEN"
            ;;
        remote)
            set_env PROCESSING_URL "$PROCESSING_URL_ANSWER"
            set_env INTERNAL_SERVICE_AUTH_TOKEN "$PROCESSING_TOKEN"
            ;;
        skip)
            # Empty rather than left pointing at a container that will not exist: an address that
            # was never set is what makes the `processing` row of `kern:health` read "not installed"
            # instead of "down".
            set_env PROCESSING_URL ''
            ;;
    esac

    case "$BROWSER_MODE" in
        remote)
            set_env BROWSER_WORKER_URL "$BROWSER_URL_ANSWER"
            set_env BROWSER_WORKER_TOKEN "$BROWSER_TOKEN"
            ;;
        skip)
            set_env BROWSER_WORKER_URL ''
            ;;
    esac

    if [ "$MAIL_MODE" = smtp ]; then
        set_env MAIL_MAILER smtp
        set_env MAIL_HOST "$MAIL_HOST_ANSWER"
        set_env MAIL_PORT "$MAIL_PORT_ANSWER"
        set_env MAIL_USERNAME "$MAIL_USER_ANSWER"
        set_env MAIL_PASSWORD "$MAIL_PASS_ANSWER"
        set_env MAIL_FROM_ADDRESS "$MAIL_FROM_ANSWER"
    fi

    # Last, so that a stack brought up by anything but this script — `make up`, a reboot — brings up
    # the same services this run chose. compose reads it from the repository root `.env`, which is a
    # symlink to this file.
    set_env COMPOSE_FILE "$COMPOSE_LIST"
}

# ---------------------------------------------------------------------------
# Doing it
# ---------------------------------------------------------------------------

in_app() {
    docker compose exec -T app "$@"
}

wait_for_database() {
    # The application's own connection, not the container's healthcheck: an external database has no
    # container here, and what has to be true is that the application can reach it. `db:monitor`
    # connects and reports, and fails if it cannot.
    attempt=0

    while [ "$attempt" -lt 60 ]; do
        if in_app php artisan db:monitor >/dev/null 2>&1; then
            printf '  the database answered\n'

            return 0
        fi

        attempt=$((attempt + 1))
        sleep 2
    done

    die 'The database never answered. `docker compose logs postgres` and `docker compose logs app` say why.'
}

install_from_answers() {
    step 'Writing apps/api/.env'
    write_environment_file
    printf '  %s, mode %s\n' "$ENV_FILE" "$ENV_MODE"

    step 'Generating the TLS certificate and key'
    # Always, whichever TLS answer was given. For the IP answer it is the certificate the edge
    # serves; for the "certificate I already have" answer it is what makes the site work until the
    # operator drops theirs in; and for the ACME answer it is never used, but `make up` refuses to
    # start without the pair on disk and compose mounts that directory either way.
    #
    # The address that was answered, plus every other address this host has — a client typing one
    # the certificate does not cover gets a second, different browser warning on top of the
    # self-signed one, which reads like a worse problem than it is. Deliberately unquoted: the
    # helper prints one address per line and each is its own argument.
    if [ -f docker/caddy/certs/kern.crt ] && [ -f docker/caddy/certs/kern.key ]; then
        printf '  there is already a pair at docker/caddy/certs — keeping it\n'
    else
        # shellcheck disable=SC2046
        scripts/generate-certs.sh "$SITE_HOST" $(scripts/host-addresses.sh 2>/dev/null || true)
    fi

    step 'Building the frontend assets'
    # Before the images, because the edge image copies apps/api/public at build time and runs no
    # build tool of its own. Skipping this gives a site with no styling and no JavaScript.
    (cd apps/api && npm ci && npm run build)

    step 'Building the images'
    docker compose build

    step 'Starting the stack'
    docker compose up -d

    step 'Waiting for the database'
    wait_for_database

    step 'Running the migrations'
    # Never on container start: a schema change applied by a restart is a schema change nobody
    # decided to apply.
    in_app php artisan migrate --force

    step 'Creating the roles and permissions'
    # Before the administrator: `kern:admin:create` grants a role, and a role has to exist as a row
    # before it can be granted. It refuses rather than creating one silently.
    in_app php artisan kern:access:sync

    step 'Opening the first administrator account'
    # On standard input, so the password reaches artisan without passing through `ps`, the shell's
    # history or `docker inspect`.
    printf '%s\n' "$ADMIN_PASSWORD" | in_app php artisan kern:admin:create --stdin "$ADMIN_EMAIL"
    ADMIN_PASSWORD=''

    finish
}

repair() {
    [ -f "$ENV_FILE" ] || die "There is no $ENV_FILE to repair. Run this without --repair."

    grep -q '^COMPOSE_FILE=' "$ENV_FILE" \
        || die "$ENV_FILE has no COMPOSE_FILE line, so compose would start a stack with no database. Copy the line from apps/api/.env.example."

    step 'Starting the stack'
    docker compose up -d

    step 'Waiting for the database'
    wait_for_database

    step 'Running any migrations that have not run'
    in_app php artisan migrate --force

    step 'Bringing the roles and permissions up to date'
    in_app php artisan kern:access:sync

    # No administrator and no environment file. The account exists by this point — `--repair` is for
    # an installation that is already there — and overwriting the file that holds the only copy of
    # this installation's secrets is not something a repair should be able to do.
    finish
}

finish() {
    step 'Asking every service whether it is really there'

    # The one test in this script, and it runs from inside the container that has to reach each
    # service — which is the only vantage point whose answer means anything. A `degraded` row exits
    # non-zero and fails the install; a `not installed` row passes, because it names a service this
    # installation was deliberately told not to run.
    if ! in_app php artisan kern:health; then
        die 'The installation is up, but something it needs did not answer. Fix that and run `make install ARGS=--repair`, or `docker compose exec app php artisan kern:health` to ask again.'
    fi

    printf '\nDone. %s\n' "$SITE_URL"

    if [ -n "$ADMIN_EMAIL" ]; then
        printf 'Sign in as %s.\n' "$ADMIN_EMAIL"
    fi

    printf '\nEvery secret this installation holds is in %s, which is not in git and is mode %s.\n' "$ENV_FILE" "$ENV_MODE"
    printf 'Write none of them down anywhere else.\n'
    printf '\nOne volume holds something nothing can recover: the key vault one. Lose it and every\n'
    printf 'encrypted field in the database stays unreadable forever. Back it up.\n'
}

# ---------------------------------------------------------------------------

heading 'Kern installer'

if [ "$REPAIR" = yes ]; then
    # The address this installation already answers on, read back rather than asked for again —
    # printed verbatim, because `http://localhost` is a legitimate APP_URL for a machine reached
    # that way and rewriting it to https would be this script telling the operator something that
    # is not true of their installation.
    SITE_URL=$(awk -F'=' '/^APP_URL=/ { print $2 }' "$ENV_FILE" 2>/dev/null || true)
    repair

    exit 0
fi

[ ! -e "$ENV_FILE" ] || die "$ENV_FILE already exists, and it holds the only copy of this installation's secrets — not overwriting it. \`$0 --repair\` re-runs everything after it."

preflight

ask_how_it_is_reached
ask_database
ask_cache
ask_vault
ask_antivirus
ask_object_storage
ask_processing
ask_browser_worker
ask_mail
ask_administrator

plan_services
print_summary

confirm 'Install this?' || die 'Nothing was written.'

install_from_answers
