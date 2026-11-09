# Kern — everyday docker compose commands.
#
# Every target here was run for real while this file was written (see
# docs/handoff/001-docker-topology.md "How to verify"). Nothing aspirational.

# The server targets — install, update, up, down, logs, shell, migrate, tls-ca… — are in
# docker/install/Makefile, which is also the whole Makefile of a server installed from a release.
include docker/install/Makefile

# The group the PHP image's `appuser` runs as (docker/php/Dockerfile) — the one other reader `make env`
# lets at the environment file.
APP_GID ?= 10001

.PHONY: env pins test-installer vendor build test lint fresh

## Create apps/api/.env — the one file this installation is configured in.
##
## It is the application's .env.example followed by the one every package ships, which is exactly
## what `php artisan kern:env` prints. Done here with `cat` because at this point nothing can run:
## compose needs this file to exist before it can start a container to run artisan in. Once the
## stack is up, use the command — after installing a package, say, to see its new variables.
## The COMPOSE_FILE line comes with it, from .env.example, listing every service — which is the
## shape to keep until an installation deliberately moves one off this machine.
env:
	@test ! -e apps/api/.env || { echo "apps/api/.env exists — not overwriting it."; exit 1; }
	@umask 077 && cat apps/api/.env.example packages/*/.env.example > apps/api/.env
	@# Readable by its owner and by the app containers' group, which reads it through the dev source
	@# mount (Vite takes the VITE_* values from it) — and by no other account on the host. Changing
	@# the group needs root or membership of it; without either the file stays open to everyone, and
	@# that is said rather than left to be found.
	@if chgrp $(APP_GID) apps/api/.env 2>/dev/null; then chmod 640 apps/api/.env; \
	else chmod 644 apps/api/.env; \
	echo "apps/api/.env is readable by every account on this host: run \`sudo chgrp $(APP_GID) apps/api/.env && sudo chmod 640 apps/api/.env\`."; fi
	@echo "Wrote apps/api/.env. Fill in every empty value — APP_KEY, the passwords, the tokens — before \`make up\`."

## Which pinned images have a newer build upstream. Usage: make pins
##
## Every image is pinned by tag and digest, so a tag rebuilt with OS patches changes nothing here until
## the pin is moved. This lists the pins that are behind and changes nothing — see scripts/pins.sh.
pins:
	@sh scripts/pins.sh

## The installer's answers, fed to its own functions and read back from the environment file they
## write — into a scratch file, never apps/api/.env. See scripts/tests/install-optional-services.sh.
test-installer:
	@sh scripts/tests/install-optional-services.sh

## Install the PHP dependencies into apps/api/vendor, for development.
##
## Development mounts apps/api over the image's copy (compose.dev.yaml), so vendor/ has to exist on
## the host. Composer runs inside the test image rather than on the host: the application needs
## PHP 8.5 and its extensions to run composer's own scripts, which a host PHP usually is not. As
## your user, so the files are yours and not root's.
vendor:
	docker build -f docker/php/Dockerfile --target test -t kern-php-test:local .
	docker run --rm -u "$$(id -u):$$(id -g)" -e COMPOSER_HOME=/tmp/composer \
		-v "$(CURDIR):/src" -w /src/apps/api kern-php-test:local composer install --no-interaction

## Build (or rebuild) every image.
build: compose-file
	docker compose build

## Run the PHP (Pest) and Python (pytest) test suites against dev-dependency
## images (docker/php/Dockerfile and docker/python/Dockerfile `test`
## stages) — never the slim runtime images, which carry no dev tooling.
test:
	docker compose build --pull=false app python-api
	docker build -f docker/php/Dockerfile --target test -t kern-php-test:local .
	docker run --rm -e APP_KEY=$${APP_KEY:-base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=} kern-php-test:local vendor/bin/pest
	docker build -f docker/python/Dockerfile --target test -t kern-python-test:local .
	docker run --rm kern-python-test:local pytest
	docker build -f docker/playwright/Dockerfile --target test -t kern-browser-test:local .
	docker run --rm --shm-size=1gb kern-browser-test:local npm test

## Run the PHP (Pint + PHPStan) and Python (ruff + pyright) linters.
lint:
	docker build -f docker/php/Dockerfile --target test -t kern-php-test:local .
	docker run --rm kern-php-test:local vendor/bin/pint --test
	docker run --rm -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= kern-php-test:local vendor/bin/phpstan analyse
	docker build -f docker/python/Dockerfile --target test -t kern-python-test:local .
	docker run --rm kern-python-test:local ruff check src tests
	docker run --rm kern-python-test:local pyright
	docker build -f docker/playwright/Dockerfile --target test -t kern-browser-test:local .
	docker run --rm kern-browser-test:local npm run lint
	docker run --rm kern-browser-test:local npm run typecheck

## Everything that must pass before a change is done, run against the ALREADY RUNNING stack.
##
## Distinct from `make test`/`make lint`, which build dedicated test images from scratch — correct
## for CI, far too slow to run after every edit. This is what to use while working.
check: check-js check-php check-packages

## Frontend: types, lint, formatting, unit tests, production build.
##
## The same checks CI's JS job runs, formatting included: without it a file Prettier would reflow
## passed here and failed only once pushed.
##
## `tsc` first: a type error makes every later failure noise. The build is included because Vite
## can fail on things tsc accepts (a bad import, an asset that will not resolve), and because the
## edge serves apps/api/public — until this runs, the browser is on the previous bundle.
check-js:
	cd apps/api && npx tsc --noEmit
	cd apps/api && npx eslint resources/js
	cd apps/api && npm run format
	cd apps/api && npx vitest run
	cd apps/api && npx vite build

## Backend: formatting, static analysis, module boundaries, tests.
##
## Pint runs on the host (it must WRITE) and is then re-checked inside the container, because the
## container mounts the source read-only. Tests use sqlite in memory; see `check-php-pg` for the
## suites that need a real Postgres.
##
## `../../packages` is the local Composer packages — `packages/` from the host, `/var/packages` from
## a container rooted at `/var/www/html`. They are linted and analysed as source, not skipped as a
## dependency: phpstan.neon already names them, Pint has to be told twice.
check-php:
	cd apps/api && vendor/bin/pint
	cd apps/api && vendor/bin/pint ../../packages
	docker compose exec -T $(SERVICE) vendor/bin/pint --test
	docker compose exec -T $(SERVICE) vendor/bin/pint --test ../../packages
	docker compose exec -T $(SERVICE) vendor/bin/phpstan analyse --no-progress --memory-limit=1G
	docker compose exec -T $(SERVICE) vendor/bin/deptrac --no-progress
	docker compose exec -T $(SERVICE) sh -c 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: vendor/bin/pest'

## The suites sqlite cannot run: the audit chain's `SELECT … FOR UPDATE`, `ilike`, `count(*) FILTER`.
##
## Points at `kern_test`, never the development database. `Tests\TestCase` refuses anything else —
## the suite runs `migrate:fresh`, and a run against `kern` destroys every row in it.
## The suites create and drop their tables, which the application's own database role cannot: it owns
## nothing, and the owner's password is blanked inside the running app (docs/SECURITY-FINDINGS.md, the
## audit hash finding, part A). So they run as the owner, read from the environment file on the host and
## handed to the container by name — never on a command line, where `ps` would show it.
AS_DB_OWNER = DB_USERNAME="$$(sed -n 's/^DB_OWNER_USERNAME=//p' apps/api/.env)" DB_PASSWORD="$$(sed -n 's/^DB_OWNER_PASSWORD=//p' apps/api/.env)"

check-php-pg:
	@$(AS_DB_OWNER) docker compose exec -T -e DB_USERNAME -e DB_PASSWORD $(SERVICE) sh -c 'APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE=kern_test vendor/bin/pest'

## Each package's own suite: Laravel booted with that package and nothing else.
##
## Distinct from `check-php`, which runs the application's suite — the feature and integration tests,
## where packages meet. These are each package's own: the unit tests of its classes, plus the proofs
## that it installs into an empty Laravel application at all. That second kind is what `check-php`
## structurally cannot tell you, and every one of them found something: unguarded foreign keys to
## tables the package does not own, a table prefix left behind in the application, a job reading a
## Horizon supervisor's timeout.
##
## Each package installs its own dev dependencies (Testbench, Pest) — it must not borrow the
## application's vendor directory, or the isolation being tested would be fictional. Run
## `make packages-install` once, and again when a package's composer.json changes.
##
## Points at `kern_pkg_test`, a database of its own, because these suites migrate from nothing.
check-packages:
	@for p in audit notifications platform documents legislation investments tax-romania; do \
		echo "── kern/$$p"; \
		$(AS_DB_OWNER) docker compose exec -T -e DB_USERNAME -e DB_PASSWORD $(SERVICE) sh -lc "cd /var/packages/$$p && vendor/bin/pest" || exit 1; \
	done

## Install each package's dev dependencies, and create the database its suite migrates into.
##
## `--ignore-platform-req` because composer runs on the host, whose PHP is older than the 8.4 the
## packages require; the suites themselves run inside the container, on 8.5.
##
## The `chmod` is not incidental. Testbench boots a skeleton Laravel application that lives inside the
## package's own vendor directory, and writes its compiled config and logs there. That directory is
## created by composer on the host and bind-mounted into a container whose PHP runs as `appuser`
## (uid 10001), so without this the suite stops on "bootstrap/cache must be writable". Widening two
## cache directories is the fix; running the tests as root instead would work and would leave
## root-owned files in your tree.
packages-install:
	docker compose exec -T postgres sh -lc 'psql -U $$POSTGRES_USER -d postgres -tc "SELECT 1 FROM pg_database WHERE datname = '"'"'kern_pkg_test'"'"'" | grep -q 1 || createdb -U $$POSTGRES_USER kern_pkg_test'
	@for p in audit notifications platform documents legislation investments tax-romania; do \
		echo "── kern/$$p"; \
		(cd packages/$$p && composer install --no-interaction \
			--ignore-platform-req=php --ignore-platform-req=ext-sodium \
			--ignore-platform-req=ext-intl --ignore-platform-req=ext-bcmath) || exit 1; \
		chmod -R a+rwX packages/$$p/vendor/orchestra/testbench-core/laravel/bootstrap/cache \
			packages/$$p/vendor/orchestra/testbench-core/laravel/storage; \
	done

## Remove what `packages-install` created — around 420 MB of per-package vendor directories.
##
## Safe: nothing but each package's own test suite uses them. The application resolves the packages
## through its own `vendor/kern/*` symlinks, which are not these.
packages-clean:
	rm -rf packages/*/vendor

## Destroy everything, including volumes, and start clean.
fresh: compose-file
	docker compose down -v
	docker compose build
	docker compose up -d
