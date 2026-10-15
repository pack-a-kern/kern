#!/bin/sh
# Every address this host answers on, one per line — the addresses someone could type into a browser.
#
# Two callers need the same list and must not disagree about it: `scripts/generate-certs.sh` puts it
# in the certificate's subjectAltName, and `scripts/install.sh` offers the first one as the default
# answer to "how will this be reached?". A certificate that covers a different set of addresses than
# the installation answers on produces a second, different browser warning on top of the self-signed
# one, which reads like a worse problem than it is.
#
# Filtered by interface name rather than by address range. Docker's own bridges carry addresses no
# client outside this host can reach, and `ip` lists interfaces that are DOWN as well — a first
# attempt matched on 172.16/12 and still picked up `docker0`'s 10.0.0.1 while that interface was
# down. Dropping lo, docker*, br-* and veth* leaves exactly the addresses worth offering.
#
# Prints nothing and exits 1 if it cannot work them out, so a caller can fall back to asking.

set -eu

command -v ip >/dev/null 2>&1 || exit 1

ADDRESSES=$(ip -4 -brief addr show 2>/dev/null \
    | awk '$3 != "" && $1 !~ /^(lo|docker|br-|veth)/ { split($3, a, "/"); print a[1] }')

[ -n "$ADDRESSES" ] || exit 1

printf '%s\n' "$ADDRESSES"
