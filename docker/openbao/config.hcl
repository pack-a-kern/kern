# OpenBao as a real server with storage on disk, replacing `server -dev`.
#
# Dev mode kept everything in memory. That is not a weaker version of this file — it is a different
# thing entirely: a restart discarded the master key, and every wrapped data key in the database
# became permanently unreadable. It happened, on 2026-09-28, to a Proxmox kernel panic. The data
# encrypted under that key could not be recovered by any means, because the key had never been
# written anywhere.
#
# `storage "file"` puts it on a named volume, so it survives a restart, a reboot and a panic like any
# other data this installation keeps.

ui = false

# TLS, with a certificate nobody has to handle. What crosses this listener is the application's token
# and every data key it unwraps, in the clear — so it was plain HTTP only while it seemed that a
# certificate meant a person issuing, mounting and renewing one. It does not: docker/certs issues this
# server's certificate from the stack's CA and renews it before it expires, the startup script reloads
# it (docker/openbao/entrypoint.sh), and the containers that call this server verify against that CA.
# The listener is on `kms_net`, which only those containers join.
listener "tcp" {
  address       = "0.0.0.0:8200"
  tls_cert_file = "/run/kern/tls/server.crt"
  tls_key_file  = "/run/kern/tls/server.key"
}

# `/openbao/file` rather than a path of our own choosing: the image already creates it owned by the
# `openbao` user it runs as, so a fresh named volume mounted there inherits that ownership. A volume
# mounted at a path the image does not contain is created owned by root, and the server cannot write
# to it — "can't create …: Permission denied" on first boot, verified.
storage "file" {
  path = "/openbao/file"
}

# Where the server tells clients it lives. Without it, operations that redirect (and the CLI's own
# calls from inside the container) can end up pointing at 127.0.0.1 from a caller's perspective.
api_addr = "https://openbao:8200"

# No `disable_mlock` here: OpenBao 2.6.2 removed mlock support altogether and refuses to start with
# the setting present at all — "OpenBao has dropped support for mlock. Please remove", verified
# against this exact image. Keeping secrets out of swap is therefore the host's business now, not
# this file's.
