# Installer plan

One command on a fresh server brings up a working installation. Every secret generated, every
migration run, every optional service either installed here or pointed at one the operator already
runs.

This is a plan, not a record of work done. `docs/handoff/` holds the records.

---

## 1. Why this is mostly configuration, not new architecture

Every service the application talks to is already reached through one variable, with no code path
that assumes it is local:

| Service | Variable | Default today | Declared in |
|---|---|---|---|
| PostgreSQL | `DB_HOST` / `DB_PORT` | `postgres` | `apps/api/.env.example:44` |
| Redis | `REDIS_HOST` / `REDIS_PORT` | `redis` | `apps/api/.env.example:56` |
| OpenBao | `OPENBAO_ADDR` | `http://openbao:8200` | `packages/platform/.env.example:34` |
| ClamAV | `CLAMAV_HOST` / `CLAMAV_PORT` | `clamav` | `packages/documents/.env.example:23` |
| Processing plane | `PROCESSING_URL` | `http://python-api:8000` | `apps/api/.env.example:159` |
| Browser worker | `BROWSER_WORKER_URL` | `http://browser-worker:8090` | `packages/tax-romania/.env.example:41` |
| Object storage | `AWS_ENDPOINT` | empty — already remote in this deployment | `apps/api/.env.example:131` |
| Mail | `MAIL_HOST` / `MAIL_PORT` | `mailpit` | `apps/api/.env.example:146` |
| AI provider | `AI_BASE_URL`, `AI_ENABLED` | empty, `false` | `packages/platform/.env.example:113` |

`apps/api/.env.example:42` already states the intent: *"DB_HOST to its address and leave that
container out."*

Three pieces of the installer also already exist and must be reused rather than rewritten:

- **`php artisan kern:env`** (`packages/platform/src/Console/Commands/Environment/BuildEnvironmentFileCommand.php`)
  assembles an environment file from the application's `.env.example` plus the one every installed
  first-party package ships, each variable keeping the sentence that explains it.
- **`Kern\Platform\Config\InstalledPackages::firstParty()`** discovers packages from
  `vendor/composer/installed.json`, filtered by the application's own vendor. Nothing lists them, so
  a package installed tomorrow is found with no change here.
- **`Kern\Platform\Settings\Services\ProbeSystemHealth`** probes `database`, `cache`,
  `key_management`, `object_storage`, `processing`, `antivirus` and `browser_worker`. The
  installer's "testing..." steps are this service, not new code. A `vpn` probe is still missing and
  belongs with §5.

### What cannot be made optional

Scanning is not a feature flag. `packages/documents/.env.example` states it: *"Scanning cannot be
turned off: an upload that cannot be scanned is refused."* The same is true of the database, Redis
and the key vault — losing OpenBao's volume makes every `encrypted_dek` unreadable
(`compose.yaml:52`).

So each service gets two or three answers, never a free choice:

| Service | Install here | Use an existing one | Do without |
|---|---|---|---|
| PostgreSQL, Redis, OpenBao, ClamAV | yes | yes | **no** |
| Object storage | yes (MinIO) | yes | no |
| Processing plane (document AI) | yes | yes | yes — `AI_ENABLED=false` |
| Browser worker (SPV) | yes | yes | yes — no SPV automation |
| Mail | yes (Mailpit, dev only) | yes (SMTP) | no |

---

## 2. The flow

```
git clone <repo> && cd kern
make install
```

```
Kern installer

Checking... docker ✓  docker compose ✓  47 GB RAM ✓  120 GB disk ✓  openssl ✓

How will this be reached?
  1) An IP address
  2) A domain name
  3) A domain name behind Cloudflare
Choice [2]: 3
  Domain: kern.example.ro
  Cloudflare API token (Zone:DNS:Edit + Zone:Zone:Read): ****
  Checking token against the zone... ✓

Database
  1) Install PostgreSQL here  (recommended)
  2) Use one I already run
Choice [1]: 1

Virus scanner  (required — an upload that cannot be scanned is refused)
  1) Install ClamAV here  (~1.5 GB RAM)
  2) Use one I already run
Choice [1]: 2
  Address [host:3310]: 10.73.4.5:3310
  Testing... PONG, ClamAV 1.5.4/28153 ✓

Key vault
  1) Install OpenBao here  (recommended)
  2) Use one I already run
Choice [1]: 1

Object storage
  1) Install MinIO here
  2) Use S3 or a MinIO I already run
Choice [1]: 2
  Endpoint: http://10.73.4.3:9000
  Access key: ****   Secret key: ****   Bucket [kern-documents]:
  Reachable only over a VPN? [y/N]: y
    1) The host is already on the VPN  (current setup)
    2) Join the VPN from a container, reachable only by services that need it
    Choice [2]: 2
    Tailscale auth key: ****
  Testing through the VPN container... bucket reachable ✓

Document AI  (reads PDFs and statements)
  1) Install the processing plane here  (~1 GB RAM)
  2) Use one I already run
  3) Skip — no AI features
Choice [1]: 1

SPV automation  (browser robot for ANAF)
  1) Install the browser worker here  (~2 GB RAM, 3.6 GB disk)
  2) Use one I already run
  3) Skip
Choice [1]: 3

Email
  SMTP host: smtp.example.ro
  Port [587]: 587   User: noreply@example.ro   Password: ****
  Sending a test message... delivered ✓

Administrator
  Email: admin@example.ro
  Password: ****   (again): ****

Summary
  8 containers, ~4 GB RAM, ~6 GB disk
  edge (caddy), app, queue, scheduler, reverb, postgres, redis, openbao, python-api, vpn
  external: clamav at 10.73.4.5, object storage at 10.73.4.3 via the VPN container
  TLS: Let's Encrypt via Cloudflare DNS, renewed automatically
Continue? [Y/n] y

  Generating secrets.................. ✓  9 values
  Writing apps/api/.env............... ✓
  Selecting services.................. ✓  COMPOSE_FILE set
  Building frontend assets............ ✓  42s
  Building images..................... ✓  3m 10s
  Starting services................... ✓
  Waiting for the database............ ✓
  Running migrations.................. ✓  214 migrations
  Initialising the key vault.......... ✓  transit mount, KEK, scoped token
  Syncing the access catalogue........ ✓
  Creating the administrator.......... ✓
  Obtaining a certificate............. ✓  kern.example.ro, renews automatically
  Health check........................ ✓  database, cache, storage, processing, antivirus, vault

Done. https://kern.example.ro — sign in as admin@example.ro

Write down nothing: every secret is in apps/api/.env, which is not in git.
The OpenBao volume is the one thing whose loss is unrecoverable. Back it up.
```

### What that replaced

`docs/DEPLOYMENT.md` §4–5 currently asks an operator to:

- generate **nine secrets** by hand, each its own copy-pasted command — `APP_KEY`, the blind-index
  key, the Laravel↔Python shared secret, three service passwords, three Reverb credentials
- fill in roughly forty values in a file assembled by `cat`
- create a TLS certificate by hand (nothing in the repository does this — see §4)
- build assets, build images, start the stack, **run migrations manually**, **create roles
  manually**, **create the first administrator manually**, then verify by hand

---

## 3. Part A — splitting compose per service

**The blocker.** There are no `profiles` anywhere in `compose.yaml`, and `app`, `queue`,
`scheduler` and `reverb` each declare `depends_on: postgres: condition: service_healthy`. Answering
"I already run PostgreSQL" therefore changes `DB_HOST` and nothing else: the local container still
starts, and the application still waits for it to be healthy before booting.

Profiles do not fix this. Verified against compose 5.6.0: a `depends_on` naming a service whose
profile is inactive does not skip it and does not pull it in — it invalidates the whole project.

```
service "app" depends on undefined service "db": invalid compose project
```

Splitting by file is therefore the only shape where leaving a service out also leaves out
everything that waits for it.

**Shape.** One file per optional service, each holding both the service and the `depends_on` that
needs it. Compose merges files, so leaving a file out removes both halves at once.

```
compose.yaml                     edge, app, queue, scheduler, reverb — always
compose.services/postgres.yaml   the postgres service + depends_on for the four PHP services
compose.services/redis.yaml
compose.services/openbao.yaml
compose.services/clamav.yaml
compose.services/minio.yaml
compose.services/python.yaml
compose.services/browser.yaml
compose.services/vpn.yaml
compose.dev.yaml            dev conveniences for services that are always present
```

The dev overrides split too. `compose.dev.yaml` used to re-declare `postgres`, `redis` and
`python-api` to publish host ports and turn on uvicorn's `--reload`. Left in place, dropping
`compose.services/postgres.yaml` from the list leaves the override declaring a `postgres` with no
image, and the project is invalid again:

```
service "postgres" has neither an image nor a build context specified: invalid compose project
```

So each of those three has a `.dev.yaml` beside its base file, listed after it, and the two come out
of `COMPOSE_FILE` together. What stays in `compose.dev.yaml` is the dev half for the edge and
the four PHP processes, plus mailpit, which is dev-only in its entirety.

The installer writes one line:

```
COMPOSE_FILE=compose.yaml:compose.services/postgres.yaml:compose.services/redis.yaml:compose.services/openbao.yaml:compose.services/python.yaml:compose.services/vpn.yaml
```

The repository root `.env` is already a symlink to `apps/api/.env`, so compose reads it with no
extra wiring.

**Networks stay as they are.** The five networks and their `internal: true` flags are unchanged;
a per-service file re-declares only the networks its service joins.

**Two traps, both guarded.** Setting `COMPOSE_FILE` at all *suppresses* compose's automatic loading
of `compose.dev.yaml`, so a development list must name it explicitly and last. And
`compose.yaml` alone has no database, so an unset or partial list starts a stack that cannot boot —
`.env.example` therefore ships the line with every service in it, and `make compose-file` (a
prerequisite of `up`, `down`, `build`, `reload` and `fresh`) refuses to run compose if
`apps/api/.env` has no such line.

**CI needs no change.** `.github/workflows/ci.yml` builds each image from its Dockerfile path
directly and never invokes `docker compose`, so the layout is invisible to it.

---

## 4. Part B — Caddy replaces nginx at the edge

`edge` is the only container that changes. Nothing else in the stack is touched.

### Why now rather than later

Earlier reasoning said to wait for a real domain. The installer *is* that moment: it asks for the
domain, so it can get a real certificate, and that is exactly the thing nginx cannot do by itself.

Today's certificate is the argument. `docker/nginx/certs/kern.crt` was generated by hand on
8 September 2026 and committed:

```
subject = C = RO, O = TaxOS Romania (dev), CN = 203.0.113.20
issuer  = same  (self-signed)
valid   = 8 Sep 2026 → 8 Sep 2027
```

Nothing in the repository generates it — no Makefile target, no entrypoint, no script. So:

1. it expires on 8 September 2027 and nothing will renew it;
2. a fresh clone **cannot start the edge** — `kern.crt` is committed but `kern.key` is gitignored
   (`.gitignore:20`), so `git clone && make up` gives a certificate with no key. `docs/DEPLOYMENT.md:312`
   claims "a self-signed pair is already committed", which is wrong;
3. it still says `O = TaxOS Romania (dev)`, the one TaxOS reference that cannot be edited.

Caddy removes all three by making the certificate something the installer obtains rather than
something a person made once.

### The TLS answers, by what the operator chose

| Answer | Caddy does | Browser trusts it | Renews itself |
|---|---|---|---|
| **Domain, ports 80/443 reachable** | ACME HTTP-01 / TLS-ALPN-01 | yes | **yes** |
| **Domain behind Cloudflare (proxied)** | ACME **DNS-01** with a CF API token | yes | **yes** |
| **IP address** | `tls internal` — Caddy's own local CA | **no**, warning | yes, but the warning stays |
| **IP address, real certificate** | Let's Encrypt IP certificate | yes | yes — **unverified, see §7** |
| **Bring your own** | `tls <crt> <key>` | depends | no |

Two things worth being precise about, since they drove the questions:

- **Self-signed: yes, Caddy does it, and it does renew.** `tls internal` runs a local CA, issues on
  first request and re-issues before expiry. That is already better than the hand-made file it
  replaces. What it does *not* do is make a browser trust it — choosing an IP still means a warning
  unless the operator installs Caddy's root CA on each client. An IP answer should say so plainly
  in the installer rather than imply SSL is solved.
- **Why Cloudflare needs a token at all.** With the orange cloud on, Cloudflare terminates TLS at
  its own edge, so an HTTP-01 challenge never reaches this server and TLS-ALPN-01 cannot work.
  DNS-01 proves control by writing a DNS record instead, which works regardless of proxying — hence
  the token, scoped to `Zone:DNS:Edit` + `Zone:Zone:Read` on that zone only, never a global key.

  There is a simpler alternative worth offering as a sub-choice: a **Cloudflare Origin CA
  certificate** is valid for 15 years, is trusted by Cloudflare for the edge→origin leg, and needs
  no ACME, no token at runtime and no renewal. It is only valid behind Cloudflare, which is exactly
  the case being configured.

### DNS-01 means building the image

The official Caddy image has no DNS provider modules. A Cloudflare token is useless without
`caddy-dns/cloudflare` compiled in, so `docker/caddy/Dockerfile` becomes two stages:

```
FROM caddy:<version>-builder AS builder
RUN xcaddy build --with github.com/caddy-dns/cloudflare
FROM caddy:<version>
COPY --from=builder /usr/bin/caddy /usr/bin/caddy
```

This is a new build dependency and needs a line in `docs/DEPENDENCY-POLICY.md`, pinned to an exact
version like every other image.

### Translating the config

`nginx.conf` + `conf.d/app.conf` are ~170 lines. The Caddyfile is roughly 35.

| Today | Caddy |
|---|---|
| `listen 8080` → 301 to https | `redir https://{host}{uri}` |
| `/healthz` on both ports | `respond /healthz "ok" 200` |
| `ssl_certificate kern.crt/key` | `tls` — per the table above |
| `ssl_protocols TLSv1.2 TLSv1.3` | default |
| nosniff / DENY / Referrer-Policy | one `header` block |
| `root` + `try_files` | `root * /var/www/html/public` + `try_files` |
| `fastcgi_pass` + `SCRIPT_FILENAME` + `resolver` + `set $upstream_app` | **`php_fastcgi app:9000`** |
| `location /app/` + 6 proxy headers + 2 timeouts | **`reverse_proxy /app/* reverb:8080`** |
| `deny /\.` | `@dot path */.*` → `respond 403` |
| `client_max_body_size 25m` | `request_body { max_size 25MB }` |
| `server_tokens off` | `header -Server` |
| `fastcgi_param REQUEST_ID $request_id` | `php_fastcgi { env REQUEST_ID {http.request.uuid} }` |

Two things are *gained* beyond config size:

- `resolver 127.0.0.11` plus `set $upstream_app` exists only because a static `fastcgi_pass` makes
  nginx refuse to boot when `app` is down (`conf.d/app.conf:78`). Caddy re-resolves per request, so
  the workaround disappears.
- Caddy is Go. The CVE class that forced `docker/nginx/Dockerfile` to purge
  `nginx-module-image-filter` and its libgd/libheif chain does not exist there.

### What must not be lost

The nginx config's comments each record a real failure. Carry every one into the Caddyfile:

- HSTS stays **off** while the certificate is untrusted, or a browser is pinned to a certificate it
  refuses. With a real certificate from the installer, HSTS can finally go **on** — it becomes a
  question the installer can answer instead of a permanent caveat.
- The Reverb publish leg (`/apps/{id}/events`) is deliberately **not** proxied. Only `/app/` is.
  Proxying both would let anything outside broadcast.
- `read_only: true`, `cap_drop: ALL`, non-root, unprivileged ports 8080/8443 — all kept. Caddy
  additionally writes to `/data` and `/config`, which need tmpfs or a volume; with ACME in use
  `/data` must be a **named volume**, or every restart re-issues the certificate and runs into rate
  limits.
- In dev, `compose.dev.yaml:82` bind-mounts `apps/api/public` and the server config over the
  baked copies. The Caddy equivalents must exist or an asset rebuild will 404 again.

### Files

```
docker/caddy/Dockerfile       new — two-stage, cloudflare DNS module
docker/caddy/Caddyfile        new — ~35 lines
docker/nginx/                 deleted, certs included
compose.yaml                  edge: new build context, /data volume, ACME env vars
compose.dev.yaml         the two dev bind-mounts, retargeted
.github/workflows/ci.yml      wherever the edge image is built
docs/DEPLOYMENT.md            §5 step 2, §7 item 2, and the stale minio services in §1
```

---

## 5. Part C — the VPN as a container

### How object storage is reached today

The host is a node on a self-hosted Tailscale network (`100.101.37.1`). A second node,
`vpn-router` at `100.101.37.6`, advertises `10.73.4.0/24`, which is where MinIO lives at
`10.73.4.3:9000`. Containers have no VPN of their own: their default route goes to a Docker bridge,
the host routes onward via `tailscale0`, and Docker's masquerade rule rewrites the source.

It works — `object_storage` probes healthy. The problem is who else it works for:

```
app             → 10.73.4.3:9000  reachable
browser-worker  → 10.73.4.3:9000  reachable      ← runs Chrome against the open internet
python-api      → 10.73.4.3:9000  reachable
```

Because the VPN belongs to the host, **every** container inherits it, including the one container
whose whole job is to load untrusted web pages. That undoes the point of `browser_net`: the Chrome
container is isolated from `data_net` by Docker and then handed a route to the private network
anyway. It is the single widest hole in the current topology.

### Shape

A `vpn` service that joins Tailscale in **userspace mode**, on its own `vpn_net`. Only services
that need a remote address join that network.

```yaml
vpn:
  image: tailscale/tailscale:<pinned>
  environment:
    TS_AUTHKEY: ${TS_AUTHKEY:?}
    TS_USERSPACE: "true"          # no /dev/net/tun, so no NET_ADMIN anywhere
    TS_SOCKS5_SERVER: ":1055"
    TS_STATE_DIR: /var/lib/tailscale
  volumes: [ tailscale_state:/var/lib/tailscale ]
  networks: [ vpn_net ]
  cap_drop: [ ALL ]
```

Userspace mode is the point: no `NET_ADMIN`, no `/dev/net/tun`, no capability added back to any
other container, and no static routes to maintain. The VPN container then forwards each remote port
it is asked to expose through its own SOCKS proxy:

```
vpn:9000  →  socks5 → 10.73.4.3:9000
```

and the application's variable becomes `AWS_ENDPOINT=http://vpn:9000` — a hostname on a Docker
network, exactly like `postgres` or `clamav`. No code change, no SDK proxy settings, no new
capability.

Result:

| | Can reach 10.73.4.0/24 |
|---|---|
| app | yes — joins `vpn_net` |
| queue | yes — joins `vpn_net` |
| browser-worker | **no** |
| python-api | **no** unless it needs a remote address of its own |
| postgres, redis, openbao | **no** |

The same container serves any other remote service behind the VPN — a remote ClamAV or a remote
Postgres is another forwarded port, not a new mechanism.

### Keeping the current behaviour

"The host is already on the VPN" stays the default answer for this deployment: it works, and
changing it is not required to ship an installer. The installer offers the container option and says
what it buys. Tailscale is also not the only thing that fits this shape — a plain WireGuard
container with a config file is the same design with a different image, and the installer can ask
which.

### Open points

- Which ports to forward is an installer question, since it depends on which services the operator
  put behind the VPN.
- `TS_AUTHKEY` is a credential in `.env` like the others. An ephemeral, pre-authorised, tagged key
  is the right kind.
- Health: the VPN container needs a probe (`tailscale status`), and `ProbeSystemHealth` should gain
  a `vpn` component so a dead tunnel reads as a named failure rather than "object storage degraded".

---

## 6. Part D — the install script

### Why a shell script and not an artisan command

At step one there is no `.env`, no `vendor/`, and no built image — so no PHP to run. The outer
driver has to be a shell script. It calls into artisan for everything that needs the framework,
once the image exists.

`make install` → `scripts/install.sh`.

### Steps

1. **Preflight.** `docker`, `docker compose`, `openssl`, `git`; RAM and disk against the choices
   made; refuse if `apps/api/.env` already exists, the way `make env` already does.
2. **Ask.** The questions in §2. Defaults in brackets, every answer re-printed in the summary
   before anything is written.
3. **Generate secrets** — the nine values `docs/DEPLOYMENT.md:231` currently asks a person to
   produce by hand:
   - `APP_KEY` — `base64:` + 32 random bytes
   - `BLIND_INDEX_KEYS` — `{"1": "<base64 32 bytes>"}`
   - the Laravel↔Python shared secret — 32 random bytes, hex
   - `POSTGRES_PASSWORD`, `REDIS_PASSWORD`, MinIO root credentials — only for services installed
     locally
   - `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`
   All from `openssl rand`. None ever printed to the terminal.
4. **Write `apps/api/.env`.** Base from `apps/api/.env.example` + every `packages/*/.env.example`,
   which is what `make env` does today and what `kern:env` does properly once composer has run.
   Then overwrite the answered keys. Mode `0600`.
5. **Write `COMPOSE_FILE`** from the chosen services (§3).
6. **Build frontend assets** before any image, because the edge image copies `apps/api/public`
   (`docs/DEPLOYMENT.md:315`).
7. **Build images**, then **start**.
8. **Wait for the database** on its healthcheck, not a sleep.
9. **Migrate** — `php artisan migrate --force`.
10. **Initialise the key vault.** For a local OpenBao its entrypoint already does the transit mount,
    the KEK and the scoped token. For an external one, the installer must verify the mount exists
    and the token can wrap and unwrap, and fail loudly if not — a wrong token here is discovered
    later as unreadable taxpayer data.
11. **Sync the access catalogue** — `php artisan kern:access:sync` (the command behind
    `SyncAccessCatalogueCommand`).
12. **Create the administrator.** `kern:admin:create`, with the password on standard input so it
    never reaches `ps`, the shell's history or `docker inspect`:

    ```
    printf '%s\n' "$password" | php artisan kern:admin:create --stdin "$email"
    ```

    It refuses if `kern:access:sync` has not run, so step 11 comes first.
13. **Obtain the certificate.** Caddy does this on first request; the installer waits for it and
    reports what was issued, or says plainly that an IP answer means a browser warning. Until the
    edge is Caddy, and as the fallback afterwards, this is `scripts/generate-certs.sh` — which the
    installer calls before step 7, since the edge image is built with the certificate mounted and
    `make up` refuses to start without it.
14. **Health check** — `ProbeSystemHealth`, printed per component. A `degraded` component is a
    non-zero exit with the component named; `not_configured` is printed and passes, because it
    names a service this installation was told not to run.

### Re-running it

The script must be safe to run twice. It refuses if `.env` exists, and offers `--repair`, which
skips questions and re-runs steps 8–14 only. Nothing in the flow is allowed to overwrite a `.env`
holding real credentials, which is already the rule `kern:env` follows.

---

## 7. What has to be built

In dependency order. Nothing after the first item can be finished before it.

| # | Work | Why first |
|---|---|---|
| 1 | ~~**Split compose per service** (§3) + `COMPOSE_FILE` + the `make` guard~~ — **done** | nothing else can skip a service |
| 2 | ~~**`kern:certs`**~~ — shipped as **`make certs`** / `scripts/generate-certs.sh`, not an artisan command: the edge needs the file before any image exists — **done** | closes the fresh-clone hole today, and stays the fallback for the IP answer |
| 3 | ~~**`kern:admin:create`**~~ — **done**. Creates the user, marks the address verified and grants the role in one transaction; `--stdin` is how the installer passes the password | step 12 has nothing to call |
| 4 | ~~**Two more health probes** — OpenBao, browser worker~~ — **done** as `key_management` (reads the KEK's version through the real driver) and `browser_worker` (`not_configured` when the installation runs none). A `vpn` probe comes with §5 | step 14 cannot verify what it cannot probe |
| 5 | **Caddy edge** (§4) | needed for a real certificate, but the stack runs without it |
| 6 | **`scripts/install.sh`** (§6) | the questions and the ordering |
| 7 | **VPN container** (§5) | optional; current host VPN keeps working |
| 8 | **`docs/DEPLOYMENT.md`** rewritten around the installer | the manual steps become the fallback path |

Items 2, 3 and 4 are small and independently useful — worth doing even if the installer stalls.

---

## 8. Must be verified before being promised

- **Let's Encrypt IP certificates in Caddy.** Let's Encrypt made IP certificates generally
  available in January 2026, with a 160-hour lifetime and HTTP-01 or TLS-ALPN-01 only (DNS-01 is not
  permitted for IPs). Caddy's support is **not confirmed**: issue
  [caddyserver/caddy#7399](https://github.com/caddyserver/caddy/issues/7399) reports failure with
  the `shortlived` profile, and community reports describe needing an updated certmagic. Until this
  is tested against the version we pin, the IP answer must offer `tls internal` and say the browser
  will warn. Do not advertise automatic trusted TLS for an IP.
- **`caddy-dns/cloudflare` against the pinned Caddy version** — build it and issue once against a
  real zone before this path ships.
- **A 6-day certificate lifetime** means renewal failure becomes visible in days, not months. If the
  `shortlived` profile is used, renewal needs monitoring, not just automation.
- **Cloudflare proxying and WebSockets** — `/app/` must survive the orange cloud.
- **Whether `kern:env` can replace the `cat` in `make env`** — it needs composer to have run, so the
  installer may need both: `cat` before `composer install`, `kern:env` after.

## 9. Out of scope

- FrankenPHP and Octane. Octane keeps the framework booted between requests, which makes any
  request-scoped state in a singleton a cross-taxpayer leak. The two classes that matter —
  `TenantContext` and `SubjectDekService` — are already `scoped()` and documented for it, so this is
  a legitimate future option, but it is a performance change with its own audit and belongs nowhere
  near an installer.
- Merging `queue`, `scheduler` and `reverb` into one container. They are three process types with
  three memory limits, three healthchecks and three network sets; scaling a merged container would
  run every scheduled job more than once.
- Auto-unseal for OpenBao. `docs/DEPLOYMENT.md:405` records the unseal key sitting in plaintext in
  its volume as a known pre-production item. The installer should *report* it, not solve it.
- The known passkey login bug (`docs/STATUS.md`).
