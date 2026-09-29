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

# Plain HTTP, and deliberately so: this listener is reachable only from `app_net`, the same network
# boundary that already protects Postgres and Redis, and nothing outside the compose network can
# address it. TLS here would mean a certificate to issue, mount and rotate for a hop that never
# leaves the host — see docs/handoff/001-docker-topology.md on the same decision for Postgres.
listener "tcp" {
  address     = "0.0.0.0:8200"
  tls_disable = true
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
api_addr = "http://openbao:8200"

# No `disable_mlock` here: OpenBao 2.6.2 removed mlock support altogether and refuses to start with
# the setting present at all — "OpenBao has dropped support for mlock. Please remove", verified
# against this exact image. Keeping secrets out of swap is therefore the host's business now, not
# this file's.
