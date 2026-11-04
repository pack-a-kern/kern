# Kern — deployment guide

A plain-language guide to running this application on a real server, for the person who owns the
project and can run `docker compose` but does not live in Laravel's world day to day. Terms are
explained the first time they appear.

Everything here is drawn from the files in this repository — `compose.yaml`, `compose.services/*`,
`compose.dev.yaml`, `apps/api/.env.example`, `apps/api/config/horizon.php`, `Makefile`,
`scripts/install.sh`, the ADRs in `docs/adr/` and `BLUEPRINT.md`. Where something is not in the
repository, or is undecided, it is called out in section 8 rather than guessed at.

---

## 1. Installing it

```bash
git clone <repository> && cd kern
make install
```

That is the whole thing. `scripts/install.sh` asks nine questions and then does every step this
guide used to list by hand.

### From a release, without building anything

The two lines above build every image on the server from a clone. A release's images are already
built, scanned and signed by CI, and a server can run those instead, with no clone, no npm and no
build. The exact commands are in the README, "Install on a server, A".

- **What the server gets.** A sixth image, `kern-install` (docker/install/Dockerfile), holds the
  release's files and nothing else: the compose files without their `.dev` overlays, the files compose
  mounts, `scripts/install.sh`, `scripts/update.sh`, `scripts/verify-images.sh`, the `.env` templates,
  a `Makefile` of server targets only (docker/install/Makefile), and `RELEASE`, which names the
  version, the registry and the repository whose CI signed it. It is `FROM scratch`: copied out with
  `docker create` and `docker cp`, never run. CI fails the release if it lacks anything compose
  mounts, or holds anything that must not ship (scripts/tests/release-bundle.sh).
- **Its signature is checked first, by hand, once.** The first install has no checker on the server
  yet, so the README's cosign command is run before a file is copied out. A bundle whose signature was
  not made by that repository's `ci.yml` at exactly that tag could carry a lying checker, which is why
  this one step is not left to the files themselves.
- **Every image after that is checked by `scripts/verify-images.sh`** against the signature CI made
  for it, and refused before anything starts if it does not match. The check needs to reach
  Sigstore's public services.
- **Later releases:** `make update VERSION=1.5.0` (scripts/update.sh). It fetches that release's
  `kern-install`, checks its signature with the checker the *current* release brought, copies its
  files in (never `apps/api/.env`, `docker/caddy/certs/` or `docker/tls/trusted/`), and hands over to
  the new release's own updater, which pulls and checks the images, migrates and recreates. Nothing
  changes if either check fails.
- The registry must let the server pull: public packages, or `docker login` on the server first.

### What it asks

| Question | The answers |
|---|---|
| How will this be reached? | An IP address (a certificate from this installation's own CA; browsers warn until it is imported) · a domain name with ports 80 and 443 open (Let's Encrypt, renewed by the edge) · a domain name behind Cloudflare's proxy (Let's Encrypt proved through DNS, with a Cloudflare API token) · a domain name with a certificate you already have |
| Database, Redis, key vault, virus scanner | Install it here · use the one I already run |
| Object storage | An `https://` endpoint, a key pair and two bucket names. **There is no "install it here"** — see section 8 |
| Document AI, SPV automation | Install it here · use the one I already run · skip it |

For a key vault, object storage, document AI or browser worker on another machine, the address must be
`https://` — the installer asks again on `http://`, because tokens, data keys and documents cross
those connections. For one whose certificate is signed by a private CA, it then asks for that CA's
certificate file, and copies it to `docker/tls/trusted/`, which the PHP containers mount.
| Email | An SMTP server · skip it, and mail is written to the log |
| The first administrator | An address and a password |

Then it prints what it is about to do — which containers, how much memory their limits add up to
against what the machine has, what is being left out — and waits for a yes.

### What it then does, in order

1. writes `apps/api/.env` at mode 0600, with every secret generated from `openssl rand`;
2. writes the `COMPOSE_FILE` line, which is the list of services this installation runs (section 2);
3. writes the names the edge's certificate must carry — the answer above and every address this host
   has — into `TLS_EDGE_NAMES`; the `certs` container issues that certificate, and every other one,
   when the stack starts (section 5);
4. builds the frontend assets — before the images, because the edge image copies `apps/api/public`
   at build time and runs no build tool of its own. *From a release:* skipped;
5. builds the images and starts the stack. *From a release:* pulls them, checks each one's signature,
   and starts the stack only if every one passes;
6. waits for the database on the application's own connection, not on a sleep;
7. runs the migrations;
8. creates the roles and permissions (`kern:access:sync`), then the first administrator
   (`kern:admin:create`), whose password reaches artisan on standard input and so never appears in
   `ps`, in shell history or in `docker inspect`;
9. asks every service whether it is really there (`kern:health`) — and **fails the install**, with
   the component named, if one that this installation was told to run did not answer.

### What it will not do

It refuses to overwrite an `apps/api/.env` that already exists: that file holds the only copy of
this installation's secrets. The way back in is:

```bash
make install ARGS=--repair
```

which asks nothing, writes nothing, and re-runs steps 5 to 9 against the installation that is
already there. It does not create an administrator — by then that account exists.

### Before you start

- **A Linux server with docker, docker compose v2, openssl and npm** — no npm when installing from a
  release. The installer checks for each and stops if one is missing.
- **Somewhere to keep documents.** An S3 bucket, a MinIO you already run, or anything that speaks
  S3. This repository ships no object storage of its own.
- **Memory.** The container limits add up to about 7 GB with everything installed locally, 3 GB with
  every service pointed elsewhere. The installer shows the figure for your answers.
- **A domain pointed at the server**, if you want a certificate browsers trust. Without one the site
  works and every visitor sees a warning.

### Doing it by hand

Section 6 is the same work step by step. It is worth knowing, because it is what the installer does
and it is the way through when one step of it fails — but on a clean server, use the command.

---

## 2. What runs where

`docker compose` starts one container per row. Each container is one running program, in its own
isolated box, talking to the others over a private network.

| Container | What it does | In which file |
|---|---|---|
| `edge` | The web server (Caddy). Every browser request hits this first; it forwards to `app` or `reverb`, and it holds the TLS certificate. Caddy can also obtain and renew that certificate itself, which is why it replaced nginx. | `compose.yaml` |
| `certs` | This installation's own certificate authority. Issues every service inside the stack its TLS certificate, and renews it — section 5. On no network at all. | `compose.yaml` |
| `app` | The Laravel application (PHP). Web pages and the API. | `compose.yaml` |
| `queue` | Runs `php artisan horizon` — works through background jobs instead of making a browser wait. Section 3. | `compose.yaml` |
| `scheduler` | Runs `php artisan schedule:work` — fires timed jobs, like the daily exchange-rate import. | `compose.yaml` |
| `reverb` | Keeps a live connection (WebSocket) open to each browser tab, so the interface can be pushed updates without the page asking. | `compose.yaml` |
| `postgres` | The database. All application data, encrypted where required (section 5). | `compose.services/postgres.yaml` |
| `redis` | In-memory store: sessions, cache, the background job queues, and broadcasting to `reverb`. | `compose.services/redis.yaml` |
| `openbao` | The key manager. Holds the master key that protects every taxpayer's own encryption key. Section 5. | `compose.services/openbao.yaml` |
| `clamav` | The virus scanner. Every uploaded file is scanned before the application will use it, and an upload that cannot be scanned is refused. | `compose.services/clamav.yaml` |
| `python-api` | A small Python service the application uses internally: it extracts text from PDFs and computes search embeddings. It never touches the database. | `compose.services/python.yaml` |
| `browser-worker` | A real headless Chrome (Playwright) that does what a *playbook* tells it — URLs, selectors, what each page looks like, all sent with the request. It knows no website of its own, and it gets no database password: if it is compromised it still cannot read the database. | `compose.services/browser.yaml` |
| `mailpit` | **Development only.** A fake mail server with a web inbox, so outgoing mail can be read instead of sent. | `compose.dev.yaml` |

### Which of them start: `COMPOSE_FILE`

`compose.yaml` holds the application alone — the edge and the four PHP processes. Every service an
installation might instead point at one it already runs lives in its own file under
`compose.services/`, and the `COMPOSE_FILE` line in `apps/api/.env` is the list compose loads.
Leaving a file out of that list leaves out both the service **and** everything that waits for it,
which is the whole reason for the split: `app`, `queue`, `scheduler` and `reverb` each wait for the
database to be healthy, so "I already run PostgreSQL" has to remove that wait as well as the
container.

The installer writes this line. `make env` writes the development one, with every service and the
dev overrides. Two things to know:

- **Setting `COMPOSE_FILE` at all stops compose loading `compose.dev.yaml` automatically.** A
  development list therefore names it explicitly, and last. A production list names neither it nor
  any `.dev.yaml` file.
- **`compose.yaml` on its own has no database.** `make up`, `down`, `build`, `reload` and `fresh`
  refuse to run if the line is missing, rather than starting a stack that cannot boot.

The repository root `.env` is a symlink to `apps/api/.env`, so compose and Laravel read the same
file.

### What development adds

`compose.dev.yaml` and the three `.dev.yaml` files beside their base files (`postgres.dev.yaml`,
`redis.dev.yaml`, `python.dev.yaml`) exist only for working on the code. Together they:

- add `mailpit`;
- publish `postgres` and `redis` on host ports, so a database client on your own machine can reach
  them. Without those files neither has a host port at all, and both sit on a network marked
  `internal: true` — no route out, and not reachable from the host;
- mount your local source into `app`, `queue`, `scheduler`, `reverb` and `python-api`, so an edit
  shows up without rebuilding the image. In production the code is baked into the image;
- turn PHP's file-change detection (opcache revalidation) back on, which is off in production;
- default `APP_ENV` to `local` and `APP_DEBUG` to `true` unless `.env` says otherwise. **Debug must
  never be on where the site is reachable** — a Laravel error page shows the whole configuration,
  passwords and keys included, to whoever provoked the error;
- serve the edge's configuration and the built frontend from the folder on disk rather than the copy
  inside the image, and turn uvicorn's `--reload` on for `python-api`.

A `.dev.yaml` file must never appear in a production `COMPOSE_FILE`.

---

## 3. How background work gets done

Some things are too slow to make a browser wait for — extracting text from a scan, asking an AI
model to read a receipt, parsing a consolidated law. Instead of doing that work inside the web
request, the application puts a note on a list ("a job"), and the `queue` container works through
the list in the background. Laravel's tool for managing this is called **Horizon**; its settings are
in `apps/api/config/horizon.php`.

There are three separate lists, each with its own worker, because each has a different reason to be
slow:

| Queue | What runs on it | Workers at once | A job may run for |
|---|---|---|---|
| `documents-ai` | Every call to an AI model: reading a document, mapping a bank statement. | **1**, in every configuration | 1500s (`HORIZON_AI_QUEUE_TIMEOUT_SECONDS`) |
| `legislation` | Downloading and indexing a law. One consolidation of the Codul fiscal is about 5 MB and some 7000 sections. | **1** | 1800s (`LEGISLATION_QUEUE_TIMEOUT_SECONDS`) |
| `default` | Everything else — notifications, exchange-rate imports, most background work. | scales up, see below | 60s (`HORIZON_DEFAULT_QUEUE_TIMEOUT_SECONDS`) |

**Why the two single-worker queues are capped at one on purpose.** The AI queue calls a single model
endpoint, which can usefully answer one request at a time; running several of these jobs at once
would only move the waiting from Horizon to the endpoint. The config file says it directly: *"the
constraint is a single model endpoint, not this machine's cores."* Ten documents at a minute each is
ten minutes of honest waiting, not a fault. The legislation queue is one worker because each job
holds a whole law in memory, and a second one would double that.

Horizon calls the manager of the workers for one queue a **supervisor**. On top of the three above it
applies one more layer, chosen by `APP_ENV`. The two values are just the names of two blocks of
numbers in that file, not a description of your hardware:

| | `documents-ai` | `legislation` | `default` |
|---|---|---|---|
| `APP_ENV=production` | 1 | 1 | **10**, adding or removing at most one worker every 3 seconds |
| `APP_ENV=local` | 1 | 1 | **3** |

Memory: Horizon's own manager process is restarted if it passes 64 MB. A worker is recycled if it
passes its own limit — 256 MB on the AI queue, 512 MB on legislation, 128 MB on `default`. The whole
`queue` container is limited to `QUEUE_MEMORY_LIMIT`, 2 GB by default: at 512 MB the kernel killed a
worker part-way through reading a law.

Neither queue retries. `tries` is 1 everywhere: a job that failed gets looked at, not run again.

---

## 4. The environment file

There is **one** file: `apps/api/.env`. The installer writes it; `make env` writes it from the
examples with every value still to fill in; `php artisan kern:env` prints the same thing once the
stack is up, which is how to see the variables a newly installed package added.

`docker compose` hands that whole file to `app`, `queue` and `scheduler`, so anything set in it
reaches the application. `reverb` gets only the names listed on its service in `compose.yaml` — its
own settings and the app's name and logging — because it is the one PHP process the internet talks to
directly, and it needs none of the database, Redis, storage or service credentials. Compose also reads it itself, for the `${...}` in the compose files —
the host ports, and the credentials the bundled Postgres, Redis and OpenBao containers are *created*
with. The direction is the same throughout: **the application's variables are the truth, and the
bundled infrastructure is created to match them.** That is why pointing `DB_HOST` or `AWS_ENDPOINT`
at a service you already run needs no second edit anywhere.

`python-api` and `browser-worker` deliberately do **not** get the whole file. Each is handed only the
few variables it needs, because they sit on networks that carry no database or object-storage
credentials.

### Written by the installer, and not to be edited by hand

`APP_KEY`, `DB_OWNER_PASSWORD`, `DB_PASSWORD`, `REDIS_PASSWORD`, `INTERNAL_SERVICE_AUTH_TOKEN`, `REVERB_APP_ID`,
`REVERB_APP_KEY`, `REVERB_APP_SECRET` and `VITE_REVERB_APP_KEY`. Seven of these are named again in the compose files with Compose's `:?`
syntax, which means **an empty one stops the stack from starting at all**, with an error naming it.
That is deliberate: a deployment missing one of these must fail to start rather than start and fail
later. Section 5 is what each one protects.

### Worth checking after an install

| Variable | What it does | What it should be |
|---|---|---|
| `APP_ENV` | Picks which block of Horizon numbers applies (section 3) and turns on Laravel's production behaviour. | `production` |
| `APP_DEBUG` | If `true`, an error page shows the whole configuration — every password and key — to whoever provoked it. | `false`, always, anywhere reachable |
| `APP_URL` | The public address. Used in mail links, as the passkey origin, and as the default list of origins allowed to open a WebSocket. | The address people actually type |
| `REVERB_ALLOWED_ORIGINS` | Which page addresses may open a live-update connection. Reverb compares the host only. | Every hostname this site is reached at. Wrong here fails **silently**: the socket connects and then delivers nothing |
| `KERN_EDGE_SITE`, `KERN_EDGE_TLS` | The name this installation answers on, and how the edge proves it is that name. Commented out means every address, with the certificate `certs` issues for `TLS_EDGE_NAMES`. | `KERN_EDGE_TLS` needs `KERN_EDGE_SITE` for Let's Encrypt; a name with no `KERN_EDGE_TLS` gets the `certs` one until yours is in `docker/caddy/certs` — see section 8 |
| `TLS_EDGE_NAMES` | The names on that certificate, comma-separated. | Every address people type, plus `localhost,127.0.0.1` |
| `KERN_EDGE_ACME_DNS`, `KERN_EDGE_DNS_TOKEN` | Proves the name through DNS instead of through a request to this server, which is the only method that works behind a proxy like Cloudflare's — it answers ports 80 and 443 itself, so the usual challenge never arrives. The first is the whole Caddy directive, because a Caddyfile has no conditionals and a provider-less `acme_dns` line stops the edge from starting; the second is the token, kept apart so Caddy reads it at runtime. | Both, or neither. Cloudflare is the one provider compiled into the edge image, and the token is scoped to `Zone:DNS:Edit` + `Zone:Zone:Read` on that one zone — never a global API key. **Even scoped to one zone, `DNS:Edit` can rewrite every record in it** — point the site elsewhere, redirect mail, obtain certificates for any name. Narrowing it to the challenge record alone needs DNS delegation (a CNAME for `_acme-challenge` into a second zone, and Caddy's `dns_challenge_override_domain`), which this installation does not set up and has not been tested — so treat the token as able to change the whole zone, keep it for this zone only, and revoke it in Cloudflare the moment it may have leaked. The token lives in `apps/api/.env`, so `app`, `queue` and `scheduler` hold it too — accepted, because they already hold credentials worth more |
| `AWS_ENDPOINT`, `AWS_BUCKET`, `QUARANTINE_BUCKET` | Where documents live, and the two buckets used: documents, and the quarantine an upload waits in while it is scanned. | **Both buckets have to exist already.** Nothing in this stack creates them |
| `AWS_USE_PATH_STYLE_ENDPOINT` | MinIO needs path-style addressing. | `true` for MinIO, `false` for real AWS S3 |
| `MAIL_*` | The mail server. `MAIL_MAILER=log` writes mail to the application log instead of sending it. | An SMTP server, for anything real — password resets and verification codes go through it |

`REVERB_HOST` is **not** the browser's address: it is where the application *publishes* an event,
inside the network, so it stays `reverb`. The browser reaches Reverb through the edge at whatever
address served the page, which is what the empty `VITE_REVERB_HOST`, `VITE_REVERB_PORT` and
`VITE_REVERB_SCHEME` mean. Set those three only for a Reverb on a hostname of its own, and rebuild
the frontend afterwards — they are baked into the assets at build time, not read at runtime.

### Review once, then leave alone

The timeouts, limits and business settings: `AI_*` (how long one extraction may take, how large an
image may be), `RETRIEVAL_EMBEDDING_*` and `EMBEDDING_*` (the local search model, off by default),
`OPERATIONS_CRON_*` (when the timed jobs run — the defaults target Romanian business hours),
`OPERATIONS_*` thresholds (what the Operations screen calls stale or stuck),
`WALLET_DEPOSIT_TERMS_MONTHS` (a business decision, not a technical one), and the
`STATEMENT_IMPORT_*` block. Each carries the sentence that explains it in the file itself.

Two things that are **not** environment variables:

- **The AI provider, model and API key are settings**, set in the running application under
  Administrare → Setări sistem and stored encrypted in the database — so an operator can change
  them without a redeploy. The `AI_*` variables are only the starting point, consulted for a field
  that has never been saved on that screen. Once it has, the database wins.
- **Which variables exist is not a fixed list.** Each package documents its own in a `.env.example`
  beside its `config/`, and `php artisan kern:env` prints the whole set.

---

## 5. Secrets, keys, and the one thing that cannot be recovered

### What the installer generates, and what each one protects

| Value | If it is wrong or lost |
|---|---|
| `APP_KEY` | Laravel's own encryption key: sessions and some internal values. Changing it signs everyone out. |
| `OPENBAO_TOKEN` | **Empty with the bundled OpenBao.** That server issues the application's token itself — scoped to the transit paths the application calls (the KEK: encrypt, decrypt, read, rotate; the blind-index key: compute, read) — publishes it to `app`, `queue` and `scheduler` on a memory-only volume (`OPENBAO_TOKEN_FILE`), and replaces it once it has `OPENBAO_TOKEN_ROTATE_DAYS` (7) left, revoking the old one at the next daily check. `kern:health` shows how many days it has, and reports key management down below `OPENBAO_ACCESS_WARN_DAYS` (3). Set this only for an OpenBao you run elsewhere, whose token you renew or replace yourself. |
| `DB_OWNER_USERNAME`, `DB_OWNER_PASSWORD` | The database's owner — the role that creates and alters tables. The bundled container is created with it, and only `migrate` connects as it; the running application has it blanked in its environment (compose.yaml). For your own database, an owner with `CREATEROLE`. |
| `DB_USERNAME`, `DB_PASSWORD` | The role the application connects as, `kern_app`. `migrate` creates it and sets its password from this line (`kern:db:grant-app-role`): it reads and writes rows, adds to the audit trail without changing it, and owns nothing — not a superuser, cannot create, alter or drop a table, cannot switch off the audit trail's trigger, cannot run a program on the database server. It used to be the owner, a superuser, so one SQL injection would have been the whole database server. |
| `REDIS_PASSWORD` | Both ends of one connection: the bundled container is created with the value the application uses. |
| `INTERNAL_SERVICE_AUTH_TOKEN` | What the Laravel application and the Python service trust each other by. Empty, and both sides refuse the request. |
| `REVERB_APP_ID` / `_KEY` / `_SECRET` | The three-part credential behind live updates. An empty secret would let anything outside broadcast events. |

To generate one by hand — rotating it, or filling in a file written by `make env`:

```bash
# Laravel's application key
docker compose run --rm app php artisan key:generate --show

# Anything else
openssl rand -hex 32
```

### How taxpayer data is encrypted

Per ADR 0002 and `BLUEPRINT.md` §4.2: every `TaxSubject` — one taxpayer's record — has its own
random 256-bit key (a "DEK"), and that key encrypts that person's sensitive fields: CNP, address,
phone, IBAN, SPV credentials, uploaded documents, extracted text. The full list is `BLUEPRINT.md`
§4.3.

That per-person key is never stored in readable form. It is itself encrypted ("wrapped") by a master
key (the "KEK") that exists only inside `openbao`, and the database holds only the wrapped version.
**So a stolen database dump, backup, replica or SQL injection is useless on its own** — whoever has
it still needs the master key to unwrap anything.

The direct consequence: **`openbao` being reachable and unsealed is a hard requirement for reading or
writing any sensitive field.** ADR 0002 rejected treating "the key manager is down" as a routine
outage to degrade around; `BLUEPRINT.md` §23 lists it as something that must raise an alert. If it is
down, encrypted reads and writes fail outright, by design.

**How the application reaches it.** Over TLS, on a network of its own (`kms_net`) that only `app`,
`queue` and `scheduler` join — Reverb, Redis and Mailpit share `app_net` with those three but cannot
even resolve `openbao`. Its certificate comes from the stack's own CA, like every other service's
(below), and the application verifies against that CA (`OPENBAO_CA_CERT`, set by compose). For an OpenBao you run
elsewhere: its `https://` address in `OPENBAO_ADDR`, and `OPENBAO_CA_CERT` only if its certificate is
signed by a private CA. Verification is never turned off.

**What the token can do.** One token unwraps every taxpayer's key it is handed — there is no
per-taxpayer lock in the key manager, and there cannot usefully be one, because the same application
serves every taxpayer and has to open every one. What protects the data is that a database copy
*without* that token is useless; a compromised application server, which has the token, can open
everything. ADR 0002 records this.

### The one unrecoverable thing

**The `openbao_data` volume.** It holds the master key, the blind-index key, and the key that unseals
them. The blind-index key is what lets an encrypted column still be searched — "does an account
already exist for this address", "is this CNP already a taxpayer" — and it used to sit in
`apps/api/.env`, where a copy of that file and of the database was enough to enumerate every CNP
offline. It now never leaves OpenBao; see `docs/adr/0020-blind-index-key-in-the-kms.md`. Lose that volume
and every wrapped key in the database stays wrapped forever — every encrypted field, for every
taxpayer, permanently unreadable. No backup of the database helps, because the database never held
the master key.

This is not theoretical. OpenBao used to run with `server -dev`, which keeps everything in memory: a
host kernel panic on 2026-09-28 restarted it empty and did exactly that. It now stores its state on
the volume, and `docker/openbao/entrypoint.sh` initialises, unseals, mounts transit, ensures the key
and issues the application's scoped token on **every** boot — because a reboot never runs
`docker compose up`, so anything a reboot must not skip cannot live in a container that only `up`
starts.

Back that volume up. It is the only part of this system where a backup is the difference between an
incident and a loss.

### Rotating a key that has been in use

Both rotatable keys — the master key and the blind-index key — take two steps, not one: rotating
mints the new version, and a second command carries everything still on the old one across. Until
that second command reports zero, the key you meant to retire is still in use and cannot be removed.
The exact flow for both, with the commands and what breaks in between, is at the end of
`packages/platform/README.md`.

### TLS inside the stack

Every connection between containers is TLS, checked against one private CA that the `certs` container
makes on first start (`docker/certs/entrypoint.sh`):

| Service | Its callers | A renewed certificate is picked up by |
|---|---|---|
| `openbao` | `app`, `queue`, `scheduler` | reloading in place (SIGHUP) |
| `postgres` | the same, and `migrate` — `verify-full`; a connection without TLS is refused (`docker/postgres/pg_hba.conf`) | reloading in place (SIGHUP) |
| `redis` | the same — its plain port is closed | reloading in place (`CONFIG SET`) |
| `browser-worker` | `app` | reloading in place, so open SPV sessions survive |
| `python-api` | `app`, `queue` | restarting itself, for a second |
| `reverb` | the edge, and the containers that publish events | restarting itself — browsers reconnect on their own |
| `edge` (reached by IP) | browsers | restarting itself, for a second |

Not yet: `clamav`, which speaks no TLS at all. Edge to PHP is a Unix socket, with nothing on a network to encrypt.

- **The CA's key** is in the `tls_ca_key` volume, which no other container mounts. Its certificate is
  in `tls_ca`, mounted read-only by every caller, with the list `kern:health` reads (`TLS_STATUS_FILE`).
- **Each service's key** is in a volume of its own (`tls_<service>`), owned by the user that service
  runs as, mode 0600 — Postgres cannot read Redis's key. A service names itself to `certs` with a
  `KERN_CERT_<name>` variable in its own compose file, so leaving it out of `COMPOSE_FILE` leaves its
  certificate out too.
- **Renewal.** Certificates last `TLS_CERT_DAYS` (365) and are renewed with `TLS_RENEW_DAYS` (30)
  left, checked every `TLS_CHECK_SECONDS`. `certs` never signals anything — that would need the Docker
  socket, which is root on the host; each service watches its own certificate
  (`docker/tls/kern-tls.sh`) and reloads itself as the table says. The CA itself lasts `TLS_CA_DAYS`
  (3650); when it is renewed, the published file carries the old CA and the new one together, and
  every certificate is issued again under the new one in the same pass.
- **The `certificates` row of `kern:health`** turns degraded once one has fewer than `TLS_WARN_DAYS`
  (14) left — which, with renewal at 30, means renewal has stopped.
- **The edge's certificate in a browser.** Reached by IP, the edge presents a certificate from this
  CA, so a browser warns until the CA is trusted. `make tls-ca` writes it to `kern-ca.crt`; import
  that once on a device as a trusted authority and renewals stop warning too. A domain with Let's
  Encrypt (`KERN_EDGE_TLS`) does not use it.
- **A service elsewhere** is configured with its `https://` address and, for a private CA, that CA's
  file in `docker/tls/trusted/` (mounted at `/run/kern/tls-trusted`): `OPENBAO_CA_CERT`,
  `AWS_CA_CERT`, `PROCESSING_CA_CERT`, `BROWSER_WORKER_CA_CERT`, `DB_SSLMODE`/`DB_SSLROOTCERT`,
  `REDIS_SCHEME`/`REDIS_CA_CERT`. Verification is never off. `make install ARGS=--repair` warns about
  any `http://` address an older installation still has.

### What must never be committed

- `apps/api/.env`, and any other `.env`. The single exception is the repository root's `.env`, which
  is committed because it is only a symlink to that file and holds nothing itself. A `.env.example`
  must never contain a real value.
- `docker/caddy/certs/*.key` **and** `*.crt` — a certificate of your own for the edge, which the
  installer copies there. Every other key lives in a volume and never in the working tree.
- `docker/tls/trusted/*` — the CA certificates of your remote services. Public, but this
  installation's own.

---

## 6. Installing it by hand

The same work as section 1, step by step. Use this when a step of the installer failed and you want
to carry on from there, or when you want to see what it did.

1. **Write the environment file.**
   ```bash
   make env
   ```
   That writes `apps/api/.env` from the application's `.env.example` plus every package's, and
   refuses to overwrite one that exists. Then fill in every value from sections 4 and 5, and edit
   the `COMPOSE_FILE` line: remove `compose.dev.yaml` and the three `.dev.yaml` entries for a
   production shape, and remove the `compose.services/<service>.yaml` of anything this machine will
   not run — pointing that service's variable (`DB_HOST`, `REDIS_HOST`, `OPENBAO_ADDR`,
   `CLAMAV_HOST`, `PROCESSING_URL`, `BROWSER_WORKER_URL`) at wherever it does run.

2. **Name the edge's certificate.** Set `TLS_EDGE_NAMES` to every address people will type, plus
   `localhost,127.0.0.1`; the `certs` container issues it, and every other certificate, when the stack
   starts. Nothing to generate by hand. With a domain, set `KERN_EDGE_SITE` and `KERN_EDGE_TLS` and
   the edge obtains a Let's Encrypt one itself on the first request instead.

3. **Build the frontend assets, before any image.**
   ```bash
   cd apps/api && npm ci && npm run build && cd ../..
   ```
   `docker/caddy/Dockerfile` copies `apps/api/public` into the edge image at build time and runs no
   JavaScript build tool itself. Skip this on a fresh checkout and the site serves with no styling
   and no JavaScript.

4. **Build the images and start the stack.**
   ```bash
   make build
   make up
   ```
   The bundled OpenBao initialises, unseals, mounts transit, creates the master key and issues the
   application's scoped token in its own startup script, on every boot — there is no separate
   init container to remember.

5. **Run the migrations.** Nothing in the image does this automatically, deliberately: a schema
   change applied by a container restart is a schema change nobody decided to apply.
   ```bash
   docker compose run --rm migrate     # or: make migrate
   ```
   A container of its own, connected as the database's owner: it applies the migrations, then creates
   or updates the application's role to match. `docker compose exec app php artisan migrate` fails
   with "permission denied", by design — the application's role cannot create a table.

   **An installation from before the application had a role of its own** (one `DB_USERNAME`, no
   `DB_OWNER_USERNAME`): `make install ARGS=--repair` moves the old role to `DB_OWNER_*`, gives the
   application `kern_app` with a generated password, and runs `migrate`. Done on the development
   installation 2026-10-20.

6. **Create the roles, then the first administrator.** Access control is a database-backed roles
   system (ADR 0014) — `SUPER_ADMIN`, `ADMIN`, `ACCOUNTANT`, `VIEWER` — not an environment variable.
   ```bash
   docker compose exec app php artisan kern:access:sync
   docker compose exec -T app php artisan kern:admin:create --stdin you@example.ro
   ```
   The second command reads the password from standard input, so pipe it in rather than typing it as
   an argument. `kern:access:grant` is how every role after the first is given, and is also the way
   back in if every administrator account is ever locked out — it needs only shell access, on
   purpose.

7. **Check it.** Section 7.

---

## 7. After installing — how to check it worked

Start here:

```bash
docker compose exec app php artisan kern:health
```

One row per service, from inside the container that has to reach it:

```
  database        ok            pgsql
  cache           ok
  key_management  ok            KEK version 1
  object_storage  ok            s3
  processing      ok
  antivirus       ok
  browser_worker  ok
```

`not installed` means this installation was told not to run that service, which is not a fault.
`DOWN` names the layer that failed and exits non-zero; the cause is in the application log, never in
the output, because an exception message from a driver can carry an address or a bucket name
(`BLUEPRINT.md` §4.10).

Then:

```bash
make smoke
```

- `https://127.0.0.1/login` → **200**. The certificate warning from `curl` is expected until this
  installation's CA is trusted (`make tls-ca`); `make smoke` passes `-k`.
- `https://127.0.0.1/api/v1/settings` → **401** while signed out. A 401 here is the healthy answer:
  the API is up and refusing an unauthenticated request.

| Check | Command | Healthy looks like |
|---|---|---|
| Every container is up | `docker compose ps` | Every service `Up` / `(healthy)`, none restarting in a loop |
| Queue workers are running | `docker compose exec app php artisan horizon:status` | Running, not paused or inactive |
| The scheduler is ticking | `docker compose logs scheduler` | New lines about once a minute. The container's own healthcheck fails it if the schedule has not run in 180 seconds |
| An upload works end to end | Upload a document in the interface | It reaches a real status rather than sitting in "processing" past `OPERATIONS_DOCUMENT_STUCK_AFTER_MINUTES` (60 by default), and reads back decrypted for a user with access |
| The key manager is reachable | Sign in, or register | If OpenBao is unreachable these fail immediately, by design (section 5), rather than appearing to work |
| Live updates work | Two browser tabs; do something that broadcasts | The second tab updates with no refresh. If not, check `REVERB_ALLOWED_ORIGINS` first — a mismatch fails silently |
| The log of one service | `make logs SERVICE=queue` | The last 200 lines, then follows |
| The images are not behind | `make pins` | Every image `current`. Each is pinned by tag and digest, so a tag rebuilt upstream with OS patches reaches this installation only when its pin is moved — `newer build` names the ones to look at (`docs/reference/verified-versions.md`) |

---

## 8. Needs a decision

Not production-ready as configured. Each of these is either stated in the repository's own comments
and ADRs or observed in the configuration — none is a guess.

1. **The key manager's unseal key sits in plaintext inside its own volume.** OpenBao no longer runs
   in dev mode: it stores its state on `openbao_data`, survives a reboot, and hands the application
   a token scoped to six transit paths rather than a root token. What remains is that
   `docker/openbao/entrypoint.sh` writes the unseal key and root token to `init.json` in that
   volume, so **anyone who can read the volume can unseal the server and decrypt everything in the
   database.** This is not a step down from what came before — `OPENBAO_TOKEN` already sat in
   plaintext in `apps/api/.env` on the same disk — and it does not weaken what envelope encryption
   is for, which is a stolen *database*. But production wants a real answer: auto-unseal against a
   cloud KMS, or Shamir shares held by different people. ADR 0002 recommends a cloud KMS/HSM or
   Vault Transit. **This is the highest-priority item before real personal data.**

2. **The TLS certificate is this installation's own unless a domain was configured.** With no
   `KERN_EDGE_SITE` the edge presents the certificate `certs` issues it — every name in
   `TLS_EDGE_NAMES` — and every visitor sees a warning until they import the CA (`make tls-ca`). Point a domain at the server and set
   `KERN_EDGE_SITE` and `KERN_EDGE_TLS`: the edge then obtains a Let's Encrypt certificate on the
   first request and renews it itself. Behind Cloudflare's proxy that first request never reaches
   this server, so there the name is proved through DNS instead — `KERN_EDGE_ACME_DNS` and a scoped
   Cloudflare token, which the edge image has the provider module for. **That path has never issued
   a certificate against a real zone**: it builds, it validates, and Cloudflare's own module rejects
   a malformed token, but the first installation to use it is the test. The fallback is a Cloudflare
   Origin certificate, which needs no ACME at all. Related: HSTS, which would force browsers to use
   HTTPS for
   this site, is deliberately **off** — with an untrusted certificate it makes the site unreachable
   with no easy recovery. It belongs in the Caddyfile's header block once a real certificate is in
   place.

3. **Object storage has to exist before an install, and so do its two buckets.** This repository
   ships no object-storage service, so `make install` has no "install it here" answer for it: a
   server with no S3 anywhere cannot be installed, because documents have nowhere to go. Nothing in
   the stack creates `AWS_BUCKET` or `QUARANTINE_BUCKET` either — they must exist already.
   `docs/INSTALLER-PLAN.md` §6 records this as the installer's one real gap. (MinIO's own last
   release was 2025-09-07, which is worth a look if it stays stale.)

4. **Every container inherits the host's VPN, including the browser worker.** Object storage is
   reached at `10.73.4.3:9000`, on a private network the *host* is a node of. Containers have no VPN
   of their own: their traffic goes out through a Docker bridge and the host routes it onward. That
   works, and it works for everything — including the one container whose whole job is to load
   untrusted web pages with a real Chrome. It is the widest hole in the current topology.
   `docs/INSTALLER-PLAN.md` §5 is the design that closes it: the VPN as its own container, joined
   only by the services that need a remote address.

5. ~~**Passkey registration is broken.**~~ Fixed 2026-11-04 — see `docs/STATUS.md` P1. A passkey
   is bound to `APP_URL`'s host when it is registered, so set that to the address people really use
   before anyone adds one.

6. **No external AI provider is chosen.** `docs/STATUS.md`'s blocked list still shows "Provider,
   region and DPA for ADR 0005 document processing" as open. ADR 0005's amendment notes this
   deployment points at a **self-hosted** vision model over a private network, so no document
   currently leaves the building — but a genuine third-party provider needs the EU-region,
   no-training data processing agreement signed off first.

7. **`BLUEPRINT.md` §35's pre-production checklist is untouched.** In the blueprint's own words: an
   external penetration test, a tested key rotation, a tested backup restore, a scan of the database
   dump and the object store for plaintext that should not be there, a scan of logs for personal
   data, cross-tenant isolation tests, an incident-response runbook, a tested account-deletion
   workflow, and formal privacy and DPA documentation. None of these are optional extras for a
   system holding CNPs, IBANs and tax records.

8. **Reverb's healthcheck proves a port is open, not that broadcasting works.** It is a TCP connect;
   Reverb exposes no documented HTTP health route. `kern:health` deliberately does not report on it
   either, because a TCP connect would be reporting something it did not check — the dashboard's own
   connection indicator already shows the property that matters. Worth remembering if live updates
   ever appear to stop silently.

9. **No real personal data belongs in this deployment until the above are resolved.** This is the
   repository's own conclusion, in `docs/handoff/037-public-exposure-hardening.md`.
