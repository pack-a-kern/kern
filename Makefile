# Kern — everyday docker compose commands.
#
# Every target here was run for real while this file was written (see
# docs/handoff/001-docker-topology.md "How to verify"). Nothing aspirational.

SERVICE ?= app

.PHONY: env up down build logs shell test lint fresh compose-file

## Refuse to run compose without a service list.
##
## compose.yaml holds the application alone, so an unset COMPOSE_FILE resolves to the edge and the
## four PHP processes with no database behind them — a stack that starts and cannot boot. The
## variable lives in apps/api/.env, which compose reads itself, so a bare `docker compose` is
## correct too; this only catches a .env written before the split.
compose-file:
	@grep -q '^COMPOSE_FILE=' apps/api/.env || { \
		echo "apps/api/.env has no COMPOSE_FILE line, so compose would start a stack with no database."; \
		echo "Copy the line from apps/api/.env.example — it lists every service."; \
		exit 1; \
	}

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
	@cat apps/api/.env.example packages/*/.env.example > apps/api/.env
	@echo "Wrote apps/api/.env. Fill in every empty value — APP_KEY, the passwords, the tokens — before \`make up\`."

## Start the full stack in the background.
up: compose-file
	@test -f apps/api/.env || { echo "apps/api/.env is missing — run \`make env\` first."; exit 1; }
	docker compose up -d

## Stop and remove containers (volumes are kept).
down: compose-file
	docker compose down

## Build (or rebuild) every image.
build: compose-file
	docker compose build

## Tail logs. Usage: make logs [SERVICE=queue]
logs:
	docker compose logs -f --tail=200 $(SERVICE)

## Open a shell in a running service. Usage: make shell [SERVICE=postgres]
shell:
	docker compose exec $(SERVICE) sh -lc 'exec bash || exec sh'

## Run the PHP (Pest) and Python (pytest) test suites against dev-dependency
## images (docker/php/Dockerfile and docker/python/Dockerfile `test`
## stages) — never the slim runtime images, which carry no dev tooling.
test:
	docker compose build --pull=false app python-api
	docker build -f docker/php/Dockerfile --target test -t kern-php-test:local .
	docker run --rm -e APP_KEY=$${APP_KEY:-base64:0000000000000000000000000000000000000000=} kern-php-test:local vendor/bin/pest
	docker build -f docker/python/Dockerfile --target test -t kern-python-test:local .
	docker run --rm kern-python-test:local pytest
	docker build -f docker/playwright/Dockerfile --target test -t kern-browser-test:local .
	docker run --rm --shm-size=1gb kern-browser-test:local npm test

## Run the PHP (Pint + PHPStan) and Python (ruff + pyright) linters.
lint:
	docker build -f docker/php/Dockerfile --target test -t kern-php-test:local .
	docker run --rm kern-php-test:local vendor/bin/pint --test
	docker run --rm -e APP_KEY=base64:0000000000000000000000000000000000000000= kern-php-test:local vendor/bin/phpstan analyse
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

## Frontend: types, lint, unit tests, production build.
##
## `tsc` first: a type error makes every later failure noise. The build is included because Vite
## can fail on things tsc accepts (a bad import, an asset that will not resolve), and because the
## edge serves apps/api/public — until this runs, the browser is on the previous bundle.
check-js:
	cd apps/api && npx tsc --noEmit
	cd apps/api && npx eslint resources/js
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
check-php-pg:
	docker compose exec -T $(SERVICE) sh -c 'APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE=kern_test vendor/bin/pest'

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
	@for p in audit platform documents legislation tax-romania; do \
		echo "── kern/$$p"; \
		docker compose exec -T $(SERVICE) sh -lc "cd /var/packages/$$p && vendor/bin/pest" || exit 1; \
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
	@for p in audit platform documents legislation tax-romania; do \
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

## Apply apps/api/.env or compose changes to the running stack.
##
## PHP edits no longer need this: `docker/php/php.dev.ini`, mounted only by compose.dev.yaml,
## turns `opcache.validate_timestamps` back on in development, so php-fpm picks up an edited file on
## the next request exactly like a normal Laravel install. Production keeps it off.
##
## Still required when a container's *environment* changes, because compose reads apps/api/.env
## only when it creates a container. If the variable is one an infrastructure container is created
## with (DB_PASSWORD, AWS_ACCESS_KEY_ID, REDIS_PASSWORD, OPENBAO_TOKEN), name that service here too,
## or the two come up disagreeing.
reload: compose-file
	docker compose up -d --force-recreate $(SERVICE)

## Is the site actually up? Answers the question a green test suite does not.
smoke:
	@curl -sk -o /dev/null -w 'login page: %{http_code}\n' https://127.0.0.1/login
	@curl -sk -o /dev/null -w 'settings api (expect 401 when signed out): %{http_code}\n' \
		-H 'Accept: application/json' https://127.0.0.1/api/v1/settings

## Destroy everything, including volumes, and start clean.
fresh: compose-file
	docker compose down -v
	docker compose build
	docker compose up -d
