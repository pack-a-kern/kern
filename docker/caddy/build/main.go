// The edge's Caddy: the standard modules plus the two the Dockerfile explains, built from the go.mod
// and go.sum beside this file so every library in the binary is the one committed here, checked
// against its recorded hash. `xcaddy build --with` resolved the same graph again on every build.
package main

import (
	caddycmd "github.com/caddyserver/caddy/v2/cmd"

	_ "github.com/WeidiDeng/caddy-cloudflare-ip"
	_ "github.com/caddy-dns/cloudflare"
	_ "github.com/caddyserver/caddy/v2/modules/standard"
)

func main() {
	caddycmd.Main()
}
