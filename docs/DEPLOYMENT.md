# TaxOS România — deployment guide

This is a plain-language guide to running this app on a real server, aimed at the person who owns
the project and can run `docker compose` but doesn't live in Laravel's world day to day. Wherever a
term needs explaining ("queue worker", "supervisor", "environment"), it's explained the first time
it appears.

Everything below is drawn from the actual files in this repository — `compose.yaml`,
`compose.override.yaml`, `apps/api/.env.example`, `apps/api/config/horizon.php`, `Makefile`, the ADRs in
`docs/adr/`, and `BLUEPRINT.md`. Where something isn't in the repo, or is ambiguous, it's called out
under "Needs a decision" at the end rather than guessed at.

---

## 1. What runs where

`docker compose` starts one container per row below. Each container is one running program, in its
own isolated box, that talks to the others over a private network.

| Container | What it does |
|---|---|
| `edge` | The nginx web server. Every request from a browser hits this first; it forwards to `app` or `reverb`. |
| `app` | The Laravel application itself (PHP). Handles web pages and the API. |
| `queue` | Runs `php artisan horizon` — this is the process that works through background jobs (document scanning, AI extraction, sending things) instead of making a browser request wait for them. See section 2. |
| `scheduler` | Runs `php artisan schedule:work` — fires off timed jobs, like the daily exchange-rate import, at the times configured in `.env`. |
| `reverb` | A separate small server that keeps a live connection (WebSocket) open to each browser tab, so the UI can be pushed updates (e.g. "your document finished processing") without the page needing to keep asking. |
| `python-api` | A small Python web service used only by the Laravel app internally. It extracts text from PDFs and (optionally) computes search embeddings for legislation. It never talks to the database directly. |
| `spv-browser` | A real headless Chrome browser (via Playwright), used to log into ANAF's SPV portal (the Romanian tax authority's online portal) on the taxpayer's behalf and pull down documents. It gets no database password — if it's compromised, it still can't read the database. |
| `clamav` | Antivirus scanner. Every uploaded file is scanned here before the app will use it. |
| `postgres` | The main database (PostgreSQL). All application data lives here (encrypted where required, see section 4). |
| `redis` | An in-memory data store used for: the session store, the cache, the background job queues, and real-time broadcasting to `reverb`. |
| `minio-init-perms` | Runs once at startup to fix file-ownership on the object storage volume, then exits. Not a long-running service. |
| `minio` | Object storage (S3-compatible) — this is where uploaded documents actually live as files. |
| `minio-init-buckets` | Runs once at startup to create the two storage "buckets" (`kern-documents`, `kern-quarantine` — like top-level folders) the app expects, then exits. |
| `openbao` | The key-management service ("KMS") that holds the master key used to protect every taxpayer's personal encryption key. See section 4. **Runs in "dev mode" as configured — not production-safe. See section 7.** |
| `openbao-init` | Runs once at startup to set up the encryption key inside `openbao`, then exits. |
| `mailpit` | **DEV-ONLY.** A fake mail server with a web inbox, so outgoing emails can be previewed during development instead of actually being sent. Only exists in `compose.override.yaml`. |

### Dev-only pieces, and what changes them

`compose.override.yaml` is loaded automatically alongside `compose.yaml` whenever you run
`docker compose up` from a checkout that has both files — which is what happens on a developer's
own machine. Its own header comment says it "must never ship to a production compose file." A
production deployment must run with **`compose.yaml` only** (e.g. `docker compose -f compose.yaml
...`, or simply not have `compose.override.yaml` present in the deploy directory). Concretely, the
override file:

- **Adds `mailpit`** entirely (not present otherwise).
- **Publishes `postgres`, `redis`, and `minio` on host ports** (`5432`, `6379`, `9000`/`9001`) so you
  can connect to them directly with a local database client. In `compose.yaml` alone, these three
  have no host ports at all and sit on a network marked `internal: true` (no route out to the
  internet, and not reachable from your host machine either).
- **Turns MinIO's web console back on** (`MINIO_BROWSER: "on"`) — it's off in `compose.yaml`.
- **Mounts your local source code straight into the `app`, `queue`, `scheduler`, and `reverb`
  containers**, and into `python-api`, so an edit to a file shows up immediately, without rebuilding
  the image. In production the code is baked into the image at build time instead.
- **Defaults `APP_ENV` to `local` and `APP_DEBUG` to `true`** for `app`/`queue`/`scheduler`/`reverb`
  (unless your `.env` says otherwise). `compose.yaml` on its own defaults these to `production` /
  `false`. Debug mode must never be on in production — a Laravel debug error page shows the entire
  server configuration, including database and encryption passwords, to whoever triggers the error.
- Serves the nginx config and the built frontend files from your live folder on disk instead of the
  copy baked into the image, and turns PHP's file-change detection ("opcache") back on so edited PHP
  files are picked up without restarting the container.

**`openbao` and `spv-browser` are not dev-only files** — both are defined in the production-shape
`compose.yaml` itself and start in a real deployment exactly as they do locally. `spv-browser` is
configured the same way in both cases and is fine to run as-is. `openbao`, however, is started with
the command `bao server -dev`, i.e. "OpenBao's own development mode": everything it holds lives only
in memory and is lost on every restart, and it is unlocked with a single "root token" taken straight
from `OPENBAO_TOKEN` in `apps/api/.env` — the same token the application presents. `compose.yaml`
says, in a comment right above the service: "DEV MODE ONLY... never anywhere but a developer's own
machine." This is covered again in sections 4
and 7 because it's the single most important thing to fix before this holds real data.

---

## 2. How work gets processed

Some things the app needs to do are too slow to make a browser wait for — extracting text from a
scanned document, calling an AI model to read a receipt, sending SPV sync progress. Instead of doing
that work inside the web request, the app puts a small note ("a job") onto a list, and the `queue`
container picks jobs off that list and does the work in the background. Laravel calls the tool that
manages this "Horizon"; its settings live in `apps/api/config/horizon.php`.

There are two separate lists ("queues") of jobs:

| Queue | What it's for | How many jobs run at once |
|---|---|---|
| `documents-ai` | Reading a scanned document (calling the AI model). | Exactly **1**, always, in every configuration. |
| `default` | Everything else — sending notifications, importing exchange rates, most background work. | Scales up: more at once when there's more configured. |

**Why `documents-ai` is capped at exactly one at a time, on purpose:** each of those jobs calls a
single AI model over the network, and that endpoint can only usefully answer one request at a time
in this setup. Running several of these jobs at once wouldn't make documents process faster — it
would just make several requests queue up at the AI endpoint instead of in Horizon, with no benefit
and a real risk of one request's slow response blocking the others. The config file spells this out
directly: *"the constraint is a single model endpoint, not this machine's cores."* Because only one
job runs, if a batch of documents comes in, later ones simply wait their turn — the config accepts a
wait of up to 1800 seconds (30 minutes) before it even considers that unusual, on the reasoning that
"ten documents at a minute each is ten minutes of honest waiting," not a fault.

In Horizon's config file, a group of these worker processes for one queue is called a
"supervisor" — think of it as the manager that starts, stops, and counts the actual PHP processes
working through one queue. This app defines two supervisors:

| Setting | `supervisor-documents-ai` (the `documents-ai` queue) | `supervisor-1` (the `default` queue) |
|---|---|---|
| How work is shared out ("`balance`") | `simple` — a fixed number of workers, no automatic adjustment. | `auto` — Laravel adds or removes worker processes on its own, based on how much work is waiting. |
| Base worker count (`maxProcesses`) | **1**, everywhere — this number is never scaled up. | 1 (this is just the starting default before either override below applies). |
| Time a job may run before being killed (`timeout`) | 600 seconds (10 minutes). | 60 seconds. |
| Retries if a job fails (`tries`) | 1 — no automatic retry. | 1 — no automatic retry. |

Both of those are the shared, baseline settings. Horizon then applies one more layer on top,
depending on a setting called `APP_ENV` — and this is worth being precise about, because the two
values it can have, `production` and `local`, are **just the names of two blocks of numbers in this
config file**, not a description of what kind of machine or hardware you're running on. Whichever
value `APP_ENV` is set to in `.env` (and passed into the `app`/`queue`/`scheduler`/`reverb`
containers by `compose.yaml`) decides which block of numbers Horizon uses:

| | `documents-ai` worker count | `default` worker count | extra `default`-queue tuning |
|---|---|---|---|
| `APP_ENV=production` | 1 (unchanged) | **10** | `balanceMaxShift: 1`, `balanceCooldown: 3` — when scaling, add/remove at most 1 worker at a time, waiting 3 seconds between adjustments. |
| `APP_ENV=local` | 1 (unchanged) | **3** | (no extra tuning specified) |

So: whatever machine you deploy to, if you set `APP_ENV=production` in `.env`, the `default` queue
can run up to 10 worker processes at once; if you leave it as `local`, only up to 3. The
`documents-ai` queue always stays at exactly 1, regardless of `APP_ENV`, because the limit there is
about the AI endpoint, not about scaling. Note that `.env.example` sets `APP_ENV=production` as the
committed default, but `compose.override.yaml` (dev-only, see section 1) quietly changes that
default back to `local` for local runs unless your `.env` says otherwise — so double-check the
running container's actual environment (section 6) rather than assuming from the file alone.

Two more Horizon numbers worth knowing:
- `memory_limit`: 64 MB for Horizon's own manager process (not the workers) — if it uses more, it's
  restarted.
- Per-worker memory: 256 MB for a `documents-ai` worker, 128 MB for a `default` worker — a worker
  using more than that is recycled.

---

## 3. Environment variables to change before going live

There is **one** file: `apps/api/.env`. Create it with `make env`, which writes it from
`apps/api/.env.example` plus the `.env.example` every installed package ships — then fill it in. (The
`.env` at the repository root is only a symlink to it, so that `docker compose`, which insists on
finding its environment file there, reads the same file Laravel does.)

`docker compose` hands that whole file to the `app`, `queue`, `scheduler` and `reverb` containers, so
anything you set in it reaches the application. It also reads the file itself, for the `${...}` in
`compose.yaml` — the host port numbers, and the credentials the bundled Postgres, Redis, MinIO and
OpenBao containers are *created* with. That is the same direction throughout: the application's
variables are the truth, and the bundled infrastructure is created to match them, which is why
pointing `DB_HOST` or `AWS_ENDPOINT` at a service you already run needs no second edit anywhere.

`python-api` and `spv-browser` deliberately do **not** get the whole file; each is handed only the few
variables it needs, because they sit on networks that carry no database or object-storage credentials.

### Must change — secrets, keys, and addresses

Every one of these either has no safe default, or currently defaults to something that is
wrong once you leave your own laptop. Four of them (`DB_PASSWORD`, `REDIS_PASSWORD`,
`AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`, `OPENBAO_TOKEN`) are marked in `compose.yaml` with
Compose's own `:?` syntax, which means: **if the variable is empty, nothing starts at all**, with an
error naming the missing variable. That's a deliberate fail-closed design, not a bug. The rest are
enforced by the application itself: without `APP_KEY` or `CRYPTO_BLIND_INDEX_KEYS` it throws rather
than running with a key nobody chose.

| Variable | What it does | What to set it to |
|---|---|---|
| `APP_ENV` | Selects which block of settings applies (see section 2) and turns on/off production safety behaviour throughout Laravel. | `production` |
| `APP_DEBUG` | If `true`, error pages show full internal detail (stack traces, config values, other secrets) to anyone who can trigger an error. | `false` — never `true` on a reachable server |
| `APP_URL` | The public web address of the site. | Your real domain or IP, e.g. `https://yourdomain.ro` |
| `APP_KEY` | The master encryption key Laravel itself uses (sessions, some internal encryption). | Generate fresh — see section 4 |
| `DB_PASSWORD` | Database password. The bundled Postgres container is *created* with it, so this one value is both ends of the connection. | A strong random password, unique to this deployment |
| `REDIS_PASSWORD` | Redis password. Empty is refused: the app's own Redis startup script "refuses to start on an unset or placeholder value." | A strong random password |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | The three-part credential that lets a browser open a live-update connection and stops an outside site from opening one using your visitor's login. | Unique random values — see section 4 |
| `REVERB_HOST` | The address browsers connect *to* for live updates. | Your real public domain/IP — `localhost` only works on your own machine |
| `REVERB_ALLOWED_ORIGINS` | Which page addresses are allowed to open a live-update connection at all; anything else is silently refused. | Every hostname the app is actually reached at (comma-separated). Leaving this wrong doesn't error — it just makes live updates quietly stop working. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | The key pair the app signs its object-storage requests with. The bundled MinIO is created with them; an S3 or MinIO you already run issues them instead. | Strong random values, unique to this deployment |
| `AWS_ENDPOINT`, `AWS_BUCKET`, `QUARANTINE_BUCKET`, `AWS_USE_PATH_STYLE_ENDPOINT` | Where object storage is, and the two buckets used: documents, and the quarantine unscanned uploads wait in. Path-style addressing is required by MinIO and must be dropped for real AWS S3. | The bundled MinIO's defaults work as shipped. For storage of your own: its URL, two buckets **you create yourself**, and the key pair above. |
| `OPENBAO_TOKEN` | The token the key-management service is reached with — and, for the bundled dev-mode server, the token it is created with (see section 4/7). | Must change from any default, but see section 7 — the bigger issue is that this whole service is dev-mode |
| `INTERNAL_SERVICE_AUTH_TOKEN` | The shared password the Laravel app and the Python service use to trust each other's requests. If empty, both sides refuse the request outright ("fail closed"). | Generate with `openssl rand -hex 32` |
| `CRYPTO_BLIND_INDEX_KEYS` | The key used to allow exact-match lookups (e.g. "does this email already have an account") on fields that are otherwise fully encrypted, without decrypting every row to check. If empty, the app crashes on **any** such lookup — including login and registration. | Generate — see section 4 |

### Should review — limits, timeouts, and business settings

Not security-critical the way the list above is, but each of these shapes real behaviour and the
shipped default may not be what you want.

| Variable | What it does | What to consider |
|---|---|---|
| `EDGE_HTTP_PORT` | Host port that maps to the web server's HTTP port. | Usually leave at `80`. |
| `AI_ENDPOINT_HOST`, `AI_ENDPOINT_IP` | Lets the app resolve a private AI endpoint's hostname (e.g. one only reachable over a VPN/LAN) that containers otherwise can't look up. | Only needed if your AI provider is on a private network; leave both blank otherwise. |
| `AI_HTTP_TIMEOUT_SECONDS` (default 10) | How long the settings screen's "test connection" button waits before giving up. | Leave, unless your provider is consistently slower than 10s to answer one token. |
| `AI_EXTRACTION_TIMEOUT_SECONDS` (default 120) | How long one document extraction call is allowed to run. Measured at 32–56s against the self-hosted model this was built against. | Raise if your AI model is slower than that. |
| `AI_EXTRACTION_MAX_TOKENS` (default 4096) | Caps the length of the AI's answer for one document. Too low and a "thinking" model can use its whole budget reasoning and return nothing. | Leave unless you see empty extraction results. |
| `AI_EXTRACTION_MAX_IMAGE_BYTES` (default 8388608, i.e. 8 MiB) | The largest document image sent to the AI model. Anything bigger is rejected as a failed extraction, not silently shrunk. | Review against your actual document sizes. |
| `AI_ANTHROPIC_VERSION` | An API version header, only relevant if the configured provider is Anthropic. | Leave unless told otherwise by the provider's docs. |
| `RETRIEVAL_EMBEDDING_ENABLED` (default `false`) | Turns on the legislation-search feature's local embedding model. | Leave off unless you're specifically enabling that feature. |
| `RETRIEVAL_EMBEDDING_*`, `EMBEDDING_*` (the whole block) | Settings for that same local embedding model, only consulted if the flag above is on. Three of them (`RETRIEVAL_EMBEDDING_DIMENSION`, `EMBEDDING_DIMENSION`, and a database column) must all agree, or search results land "in the wrong space." | Leave at shipped defaults unless you're changing the embedding model, and if you do, change all three together. |
| `OPERATIONS_SCHEDULE_TIMEZONE`, `OPERATIONS_CRON_FX_IMPORT_DAILY` | When the daily exchange-rate import runs. | Defaults already target Romanian business hours (`Europe/Bucharest`, weekdays 14:00) — review only if that's wrong for you. |
| `OPERATIONS_HEARTBEAT_*`, `OPERATIONS_RUN_*`, `OPERATIONS_FX_MAX_AGE_DAYS`, `OPERATIONS_DOCUMENT_STUCK_AFTER_MINUTES`, `OPERATIONS_FAILED_JOBS_LIMIT`, `OPERATIONS_FX_BACKFILL_MIN_YEAR` | Various thresholds behind the internal "Operations" screen (is the scheduler alive, is FX data stale, etc). | Sensible defaults already; only worth tuning once you've seen the Operations screen and have an opinion. |
| `WALLET_DEPOSIT_TERMS_MONTHS`, `WALLET_DEPOSIT_DEFAULT_DAY_COUNT` | The term-deposit lengths and interest day-count convention offered in the "add deposit" form. | Business decision, not a technical one — review with whoever owns that feature. |

### Can leave alone

| Variable | Why it's safe to leave |
|---|---|
| `APP_NAME` | Cosmetic — shows up in notifications and the Horizon dashboard title. |
| `DB_DATABASE`, `DB_USERNAME` | Fine at their shipped defaults (`kern`) unless you have a naming reason to change them. The bundled Postgres is created with whatever they say. |
| `CRYPTO_BLIND_INDEX_CURRENT_VERSION` | Only changes when you're deliberately rotating the blind-index key — leave at `1` otherwise. |
| `VITE_DEFAULT_PALETTE` | Just picks which colour theme greets a user before they choose one. |
| `POSTGRES_DEV_PORT`, `REDIS_DEV_PORT`, `MINIO_DEV_API_PORT`, `MINIO_DEV_CONSOLE_PORT`, `MAILPIT_UI_PORT`, `VITE_DEV_PORT` | These only do anything when `compose.override.yaml` (dev-only, section 1) is loaded. In a production deployment running `compose.yaml` alone, they have no effect at all. |

### Worth knowing about

- **AI provider, model, and API key are settings, not environment variables.** They're set inside the
  running app, under Administrare → Setări sistem, and stored (encrypted where sensitive) in the
  database — precisely so an operator can change them without redeploying.
  `AI_ENABLED`/`AI_PROVIDER`/`AI_BASE_URL`/`AI_MODEL`/`AI_API_KEY` in `kern/platform`'s
  `.env.example` are only the starting point, consulted for a field that has never been saved on that
  screen. Once it has, the database wins.
- **Which variables exist is not a fixed list.** Each package documents its own, in a `.env.example`
  beside its `config/`. `docker compose exec app php artisan kern:env` prints the whole set — the
  application's and every installed package's — with the sentence explaining each one.

---

## 4. Secrets and keys

### What must be generated fresh for every deployment

Never reuse the values from `.env.example`, from a developer's machine, or from any other
deployment. Run these against the built image so the PHP version/extensions match exactly:

```bash
# Laravel's own application key
docker compose run --rm app php artisan key:generate --show

# The blind-index key (JSON object, version "1" mapped to a random 32-byte key)
docker compose run --rm app php -r 'echo json_encode(["1" => base64_encode(random_bytes(32))]), PHP_EOL;'

# The Laravel <-> Python shared secret
openssl rand -hex 32

# Database, Redis, and MinIO passwords — any strong random string, e.g.
openssl rand -hex 24
```

For `REVERB_APP_ID`, `REVERB_APP_KEY`, and `REVERB_APP_SECRET`, the repository doesn't document a
specific generation command — they just need to be unique, unguessable strings (this is the same
three-part credential scheme the Pusher protocol uses). `openssl rand -hex 20` for each is a
reasonable choice.

### What OpenBao holds, and why it matters

Per ADR 0002 and `BLUEPRINT.md` §4.2: every `TaxSubject` (a taxpayer's record) gets its own random
256-bit encryption key ("DEK"), used to encrypt that person's sensitive data — CNP, address, phone,
IBAN, SPV credentials, uploaded documents, OCR output, and more (the full list is in
`BLUEPRINT.md` §4.3). That per-subject key is never stored in plain form. Instead, it's itself
encrypted ("wrapped") by a master key ("KEK") that lives only inside `openbao`, and the database
only ever stores the wrapped, unreadable version. This means a stolen database dump or a stolen
object-storage bucket is useless on its own — whoever has it still needs the OpenBao master key to
unwrap anything.

The direct consequence: **`openbao` being reachable and unlocked is a hard requirement for the app
to read or write any sensitive field.** ADR 0002 states this plainly — treating "KMS unavailable" as
a routine outage to degrade gracefully around was rejected on purpose; `BLUEPRINT.md` §23 lists "KMS
unavailable" as something that must trigger an alert, not something the app should quietly work
around. If OpenBao is down or its key is lost, encrypted reads and writes fail outright, by design.

That makes the current setup's biggest weakness the same as its most important detail: `openbao`
runs with `bao server -dev`, which keeps everything **in memory only**. Every restart of that
container wipes the master key, and `openbao-init` has to recreate it from scratch — which means
every DEK wrapped under the old key becomes permanently unreadable. This is fine for a demo; it is
not survivable for real data. See section 7.

### Rotating a key after it has been in use

Both rotatable keys — the master key in OpenBao and the blind-index key above — take two steps, not
one: rotating mints the new version, and a second command carries everything still on the old one
across. Until that second command reports zero, the key you meant to retire is still in use and
cannot be deleted. The exact flow, both keys, with the commands and what breaks in between, is at the
bottom of `packages/platform/README.md`.

### What must never be committed

- `apps/api/.env` — excluded via `.gitignore`, along with any other `.env`. The single exception is
  the repository root's `.env`, which is committed because it is only a symlink to `apps/api/.env`
  and holds nothing itself. Any `.env.example` must **never** contain a real value.
- `docker/nginx/certs/*.key` — the TLS private key is excluded via `.gitignore` (line 16). The
  matching `.crt` (certificate) file is *not* excluded and is currently committed — that's fine for
  a self-signed dev certificate, but revisit this once you move to a real, CA-issued certificate.

---

## 5. Deployment steps

Derived from `Makefile`, `compose.yaml`, and `docker/nginx/Dockerfile`. Run these in order, from a
clean checkout on the server.

1. **Create the environment file and fill it in.**
   ```bash
   make env
   ```
   That writes `apps/api/.env` from the application's `.env.example` and every package's. It refuses
   to overwrite one that already exists. Fill in every "must change" variable from section 3, using
   the generation commands in section 4.

2. **Put a TLS certificate in place.** `edge` expects a certificate and key at
   `docker/nginx/certs/kern.crt` and `docker/nginx/certs/kern.key` (mounted read-only into the
   container). A self-signed pair is already committed for development; for a real deployment,
   replace it (see section 7 — this is one of the "needs a decision" items).

3. **Build the frontend assets before building any images.** `docker/nginx/Dockerfile` copies
   `apps/api/public` into the `edge` image at build time and does not run any JavaScript build tool
   itself — its own comment says "This image does not run Vite itself — CI builds assets first, then
   this image is built." So, from `apps/api`:
   ```bash
   cd apps/api
   npm ci
   npm run build
   cd ..
   ```
   If you skip this on a brand-new checkout, `apps/api/public/build` won't exist yet and the site
   will serve with no styling or JavaScript.

4. **Build the images.**
   ```bash
   make build          # = docker compose build
   ```

5. **Start the stack**, running the production shape only (no `compose.override.yaml`):
   ```bash
   make up             # = docker compose up -d
   ```
   This also runs the one-shot setup containers automatically, in the right order, because of the
   `depends_on` conditions in `compose.yaml`: `minio-init-perms` fixes storage ownership, then
   `minio` starts, then `minio-init-buckets` creates the `kern-documents` and `kern-quarantine`
   buckets, then `openbao-init` sets up the encryption master key, then `app`/`queue` start (they
   wait on Postgres, Redis, and the buckets being ready first).

6. **Run the database migrations.** Nothing in the image runs these automatically — this is a manual
   step, deliberately, so a schema change is never applied by accident on container restart:
   ```bash
   docker compose exec app php artisan migrate --force
   ```
   (`--force` is required because `APP_ENV=production` makes Artisan ask for interactive
   confirmation otherwise, which would just hang in a non-interactive deploy.)

7. **Create the roles and the first administrator.** Access control is a proper database-backed
   roles system (ADR 0014) — `SUPER_ADMIN`, `ADMIN`, `ACCOUNTANT`, `VIEWER` — not an environment
   variable. First, project the role/permission catalogue into the database:
   ```bash
   docker compose exec app php artisan kern:access:sync
   ```
   Then grant yourself the top role (this is also how you'd recover if every admin account were
   ever locked out — it only needs shell access to the server, on purpose):
   ```bash
   docker compose exec app php artisan kern:access:grant you@example.com SUPER_ADMIN
   ```

8. **Confirm the scheduler and queues are actually running** — see section 6 below.

That covers "clean server to running app." `queue`, `scheduler`, and `reverb` don't need any extra
step to start — `command:` in `compose.yaml` already starts `php artisan horizon`,
`php artisan schedule:work`, and `php artisan reverb:start` respectively as soon as their
containers come up in step 5.

---

## 6. After deploying — how to check it worked

```bash
make smoke
```
This runs two checks defined in `Makefile`:
- `https://127.0.0.1/login` — expect **200**. This is the login page; if you get a certificate
  warning from `curl`, that's expected with the self-signed certificate (see section 7) — `make
  smoke` uses `-k` to ignore it.
- `https://127.0.0.1/api/v1/settings` — expect **401** (unauthorized) *while signed out*. Getting a
  401 here is the healthy result — it means the API is up and correctly refusing an unauthenticated
  request, not a failure.

Beyond that:

| Check | Command | What healthy looks like |
|---|---|---|
| All containers report healthy | `docker compose ps` | Every service shows `Up` / `(healthy)`, not `unhealthy` or restarting in a loop. |
| Queue workers are running | `docker compose exec app php artisan horizon:status` | Reports Horizon as running, not paused or inactive. |
| Scheduler is actually ticking | `docker compose logs scheduler` | New log lines appear roughly once a minute; the container's own healthcheck (`docker/php/scheduler-healthcheck.php`) already fails the container if the schedule hasn't run in the last 180 seconds. |
| A document upload works end-to-end | Upload a test document through the UI | It should reach a real status (not stuck "processing" past `OPERATIONS_DOCUMENT_STUCK_AFTER_MINUTES`, default 60 minutes) and show up decrypted for a signed-in user with access. |
| Encryption/KMS is reachable | Try registering or logging in | If OpenBao is unreachable or misconfigured, these fail immediately (by design — see section 4) rather than silently succeeding with broken data. |
| Live updates work | Open two browser tabs, trigger something that broadcasts (e.g. process a document) | The second tab updates without a manual refresh. If it doesn't, check `REVERB_ALLOWED_ORIGINS` first (section 3) — a mismatch here fails silently, with no visible error. |
| Logs for one service | `make logs SERVICE=queue` (or `app`, `scheduler`, `reverb`, etc.) | Tails the last 200 lines and follows new ones. |

---

## 7. Needs a decision

Things that are not production-ready as currently configured. These are not guesses — each is
either stated directly in the repository's own comments/ADRs, or observed directly in the config
files.

1. **OpenBao (the encryption key service) runs in "dev mode."** `compose.yaml` starts it with
   `bao server -dev`, which keeps the master key in memory only and unlocks with a single root token
   from `.env`. Every restart loses the key, which permanently destroys access to every taxpayer
   record's data (crypto-shredding by accident, not by choice). ADR 0002 recommends "cloud KMS/HSM
   or HashiCorp Vault Transit" for production; `docs/handoff/037-public-exposure-hardening.md` says
   outright: "OpenBao runs in dev mode... Fine for a demo, never for real taxpayer data." **This is
   the single highest-priority item before any real personal data goes into this system.**

2. **The TLS certificate is self-signed.** `docker/nginx/certs/kern.crt`/`.key` cover the current
   public IP, a Tailscale IP, and `localhost` — browsers will show a security warning to every
   visitor. `docs/STATUS.md`'s deployment note says explicitly: point a real domain at the server and
   issue a certificate via Let's Encrypt. Related: HSTS (a setting that would force browsers to
   always use HTTPS for this site) is deliberately **not** turned on yet, because with a self-signed
   certificate that would make the site unreachable with no easy recovery — turn it on only after a
   real certificate is in place.

3. **MinIO is still what's bundled, even though moving off it is now only a setting.**
   `AWS_ENDPOINT`, `AWS_BUCKET`, `QUARANTINE_BUCKET`, `AWS_DEFAULT_REGION` and
   `AWS_USE_PATH_STYLE_ENDPOINT` are all plain variables in `apps/api/.env`, so a real S3-compatible
   provider needs no file edited. Two things do not follow automatically: the two buckets have to be
   created (the bundled `minio-init-buckets` container does that only for the bundled MinIO), and the
   `minio` and `minio-init-buckets` containers should then not be started at all. MinIO's own last
   release was 2025-09-07 — worth revisiting if it stays stale.

4. **A known, unresolved security bug: passkey login is broken.** `docs/STATUS.md` records it
   directly: `POST /auth/passkeys/registration` fails because the registration challenge isn't found
   by the follow-up call, "affects the real app, not just tests," and is flagged there as "High — a
   security feature that silently does not work." Marked as deferred by the owner as of 2026-09-08,
   to be fixed after other screens are done — worth confirming that's still the intended order before
   launch.

5. **No external AI provider is selected yet.** `docs/STATUS.md`'s blocked-items list still shows
   "Provider, region and DPA for ADR 0005 document processing" as open. ADR 0005's 2026-09-11
   amendment notes the current deployment instead points at a **self-hosted** vision model over a
   private network, so no document currently leaves the building for AI processing — but if that
   changes to a genuine third-party provider, the EU-region/no-training data processing agreement
   this ADR requires still needs to be signed off first.

6. **`BLUEPRINT.md` §35's full pre-production checklist is untouched.** Items not yet done, in the
   blueprint's own words (translated): external penetration test, tested key rotation, tested backup
   restore, a scan of the database dump and object store for any plaintext that shouldn't be there, a
   scan of logs for personal data, cross-tenant isolation tests, an incident-response runbook, a
   tested account-deletion workflow, and formal privacy/DPA documentation. None of these are
   optional extras for a system holding CNPs, IBANs, and tax records.

7. **No real personal data should go into this deployment until the above are resolved.** This
   isn't a guess — it's the explicit conclusion of the repository's own hardening note
   (`docs/handoff/037-public-exposure-hardening.md`): *"Still a dev deployment — self-signed
   certificate and dev-mode OpenBao — so no real taxpayer data belongs in it."*

8. **Unverified: Reverb has no documented HTTP health endpoint.** The compose healthcheck for
   `reverb` only confirms the WebSocket port accepts a TCP connection, not that broadcasting is
   actually working end to end — `compose.yaml`'s own comment notes this ("Reverb exposes no
   documented HTTP health route as of this check"). Worth keeping in mind if live updates ever appear
   to silently stop.
