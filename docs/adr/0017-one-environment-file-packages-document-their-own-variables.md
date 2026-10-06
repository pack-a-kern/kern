# ADR 0017 — One environment file, and every package documents its own variables

**Status:** accepted
**Date:** 2026-09-25
**Extends:** ADR 0016 (installable packages). A package that ships its own migrations, routes and
config was still leaving its environment variables for the application to write down.

## Context

There were two environment layers, and the boundary between them was invisible.

The root `.env` held 33 variables and was read by `docker compose` — partly for `${...}` substitution
into `compose.yaml`, partly to be forwarded into the containers by an `environment:` block that named
each variable by hand. `apps/api/.env` held 63 more and was read by Laravel. Forty of those were
shadowed by the compose block, and sixteen of the forty held *wrong* values that had simply never
mattered: `DB_HOST=127.0.0.1`, an empty `DB_PASSWORD`, a stale `APP_KEY`, Reverb secrets from a
different installation. Nothing failed, because the real environment variable wins and the file's
copy was never consulted — so the file an operator would open to change a setting was, for those
sixteen, fiction.

The compose block also decided things it had no business deciding. `AWS_ENDPOINT`,
`AWS_BUCKET`, `QUARANTINE_BUCKET` and `AWS_DEFAULT_REGION` were written into `compose.yaml` with no
variable behind them, so moving to a MinIO or S3 that already existed elsewhere meant editing the
compose file — and pointing at a managed Postgres meant the same. The credential direction ran
backwards for the same reason: the application's `DB_PASSWORD` was derived from the *container's*
`POSTGRES_PASSWORD`, and its `AWS_ACCESS_KEY_ID` from `MINIO_ROOT_USER`, which only reads correctly
if the bundled container is the only thing there will ever be.

And after ADR 0016, 147 of the roughly 210 variables the system reads belong to packages, not to the
application. They were all documented in `apps/api/.env.example`, which is the one file a package
installed into somebody else's Laravel application does not come with.

## Decision

**One file: `apps/api/.env`.** It is where Laravel looks, so it is where everything lives.
`compose.yaml` configures nothing about the application: it hands the whole file to the `app`,
`queue`, `scheduler` and `reverb` containers with `env_file:`, and a variable can no longer be set in
the file and silently fail to arrive.

The repository root's `.env` is a **symlink** to it. `COMPOSE_ENV_FILES` was tried first and is not
usable: Compose honours it only from the shell environment, not from the `.env` it reads by default —
so a pointer file cannot work, while a symlink means Compose's own default path and Laravel's are the
same file. Vite reads that file too, which is why the `VITE_*` variables sit in it rather than
anywhere else.

**The credentials flow outward, from the application to the infrastructure.** The bundled Postgres is
created with `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD`, MinIO with `AWS_ACCESS_KEY_ID` /
`AWS_SECRET_ACCESS_KEY`, OpenBao with `OPENBAO_TOKEN`. `POSTGRES_PASSWORD`, `MINIO_ROOT_USER` and
`OPENBAO_DEV_ROOT_TOKEN` are gone as names. Pointing `DB_HOST` or `AWS_ENDPOINT` at a service that
already exists is then one line, and the container that is no longer wanted is simply not started.

`python-api` and `browser-worker` deliberately do **not** get the file. They sit on networks that carry
no database or object-storage credentials (BLUEPRINT.md §3.3) and each names the few variables it
needs; handing them the whole environment would undo that.

**Each package documents the variables it reads, in a `.env.example` beside its `config/`.** A
package is the only thing that knows what it reads, and it is installed into applications this
repository will never see. `php artisan kern:env` prints the application's file followed by every
installed package's, which is the whole environment and the way an installation's `.env` is created.
Which packages are installed is discovered from `vendor/composer/installed.json`, filtered to the
vendor the application itself is published under — so nothing lists the packages by name, and a
package added tomorrow is found the moment Composer installs it.

`tests/Feature/Config/ConfigKeysTest.php` enforces both halves: every variable a package's config
reads is named in that package's own example, and every variable the application's config reads is
named either in its own or in an installed package's. The application may lean on a package it
installs; a package may not lean on an application it has never seen.

## Consequences

- An operator has one file to open, and every value in it is a value that takes effect. The sixteen
  fictional ones are gone.
- `make env` writes that file from the examples with `cat`, because at that moment nothing can run:
  Compose needs the file to exist before it can start a container to run `artisan` in. The command is
  for afterwards — after installing a package, to see what it added.
- `APP_LOCALE`, `LOG_LEVEL`, `SESSION_LIFETIME` and the like now reach production containers, where
  before they applied only in development, through the bind mount, by accident.
- Two variables no longer exist: `SETTINGS_ADMIN_USER_IDS`, replaced by roles in ADR 0014 and still
  being passed to the containers, and the `OPENBAO_DEV_ROOT_TOKEN` fallback, which had nothing left
  to fall back from.
- The root `.env` is committed, unlike every other `.env`, because a symlink holds no secret. Every
  real `.env` stays ignored and out of every image.
- A developer on Windows would need symlink support. This project is Linux and Docker only, and
  BLUEPRINT.md §3 assumes it throughout.
