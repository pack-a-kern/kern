#!/bin/sh
# Generate the edge's TLS certificate and key.
#
# A shell script and not `php artisan kern:certs`, though docs/INSTALLER-PLAN.md §7 named it that
# way: the edge needs this file to start, and an artisan command needs an image built and a
# container to run it in. On a fresh clone there is neither. openssl is on every host that can run
# this stack, and the installer checks for it before anything else.
#
# Nothing here is committed. The certificate used to be — `kern.crt` in git, `kern.key` ignored by
# .gitignore — which meant a fresh clone had a certificate with no key and `edge` would not start,
# with nothing saying why. A private key must never be committed and a certificate generated from
# it is worth no more than the key, so both are now ignored and this script is how they appear.
#
# Self-signed, so a browser will warn. That is the honest state of an installation reached by IP;
# a real certificate needs a domain, and docs/INSTALLER-PLAN.md §4 is where that goes.
#
#   scripts/generate-certs.sh                      every address this host answers on, plus localhost
#   scripts/generate-certs.sh kern.example.ro      that name, plus localhost
#   scripts/generate-certs.sh kern.example.ro 10.73.4.2
#   FORCE=1 scripts/generate-certs.sh              replace an existing pair
#
# Usable from `make certs`, which passes HOST=... through.

set -eu

DIR="${CERT_DIR:-docker/caddy/certs}"
CRT="$DIR/kern.crt"
KEY="$DIR/kern.key"
DAYS="${CERT_DAYS:-825}"
ORG="${CERT_ORG:-Kern}"
COUNTRY="${CERT_COUNTRY:-RO}"

# The uid:gid the edge container runs as (docker/caddy/Dockerfile). The pair is bind-mounted into
# that container read-only, so the key has to be readable by *it*, not only by whoever ran this
# script: a key left root-owned and 0600 makes the edge start, fail to load TLS, and take the site
# down with nothing in the error saying "permissions". That is what the first version of this
# script did.
CERT_UID="${CERT_UID:-10001}"
CERT_GID="${CERT_GID:-10001}"

command -v openssl >/dev/null 2>&1 || {
    echo "openssl is not installed, and it is what makes the certificate." >&2
    exit 1
}

if [ -f "$CRT" ] && [ "${FORCE:-0}" != "1" ]; then
    echo "$CRT exists. Its details:"
    openssl x509 -in "$CRT" -noout -subject -dates -ext subjectAltName 2>/dev/null | sed 's/^/  /'
    echo
    echo "Run with FORCE=1 to replace it — every browser and client that trusted the old one"
    echo "will have to be shown the new one."
    exit 1
fi

# Addresses to put in the certificate. A name a client types that is not in here produces a second,
# different browser warning ("certificate is not valid for this address") on top of the self-signed
# one, which reads like a worse problem than it is.
if [ "$#" -gt 0 ]; then
    NAMES="$*"
else
    # Every address this host answers on, so the certificate covers the public IP, the VPN address,
    # and anything else, without a person having to list them. `ip` is on every Linux host running
    # containers; if it is missing, pass the addresses as arguments instead.
    #
    # Filtered by interface name rather than by address range. Docker's own bridges carry addresses
    # no client outside this host can reach, and `ip` lists interfaces that are DOWN as well —
    # a first attempt matched on 172.16/12 and still picked up `docker0`'s 10.0.0.1 while that
    # interface was down. Dropping lo, docker*, br-* and veth* leaves exactly the addresses someone
    # could type into a browser.
    NAMES=$(ip -4 -brief addr show 2>/dev/null \
        | awk '$3 != "" && $1 !~ /^(lo|docker|br-|veth)/ { split($3, a, "/"); print a[1] }' \
        || true)
    [ -n "$NAMES" ] || {
        echo "Could not work out this host's addresses. Pass them as arguments:" >&2
        echo "  scripts/generate-certs.sh 203.0.113.10 kern.example.ro" >&2
        exit 1
    }
fi

# localhost is always included: the edge's own healthcheck and `make smoke` both reach it that way.
SAN=""
CN=""
for n in $NAMES localhost 127.0.0.1; do
    case "$n" in
        *[!0-9.]*) entry="DNS:$n" ;;
        *)         entry="IP:$n" ;;
    esac
    case ",$SAN," in *",$entry,"*) continue ;; esac
    SAN="${SAN:+$SAN,}$entry"
    [ -n "$CN" ] || CN="$n"
done

mkdir -p "$DIR"

# `-noenc` rather than the deprecated `-nodes`: the edge reads this key unattended at boot, so it
# cannot carry a passphrase. The key is created with a tight umask and chmod'd below, because
# openssl writes it 0644 by default.
umask 077
openssl req -x509 -newkey rsa:2048 -sha256 -days "$DAYS" -noenc \
    -keyout "$KEY" -out "$CRT" \
    -subj "/C=$COUNTRY/O=$ORG/CN=$CN" \
    -addext "subjectAltName=$SAN" \
    -addext "basicConstraints=critical,CA:FALSE" \
    -addext "keyUsage=critical,digitalSignature,keyEncipherment" \
    -addext "extendedKeyUsage=serverAuth" \
    >/dev/null 2>&1

# 0640 with the group the edge runs as, rather than 0600: the container user has to read it, and
# nothing else on the host should. The certificate itself is public by definition.
chmod 640 "$KEY"
chmod 644 "$CRT"

if ! chown "$CERT_UID:$CERT_GID" "$KEY" "$CRT" 2>/dev/null; then
    echo "Could not give $KEY to uid $CERT_UID — this needs root, and the edge container cannot"
    echo "read the key without it. Run:"
    echo "  sudo chown $CERT_UID:$CERT_GID $KEY $CRT"
    echo
fi

echo "Wrote $CRT and $KEY"
openssl x509 -in "$CRT" -noout -subject -dates -ext subjectAltName 2>/dev/null | sed 's/^/  /'
echo
echo "Self-signed, so browsers will warn. Valid for $DAYS days — there is nothing that renews it,"
echo "so note the date above. A real certificate needs a domain name."
