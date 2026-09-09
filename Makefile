# TaxOS România — everyday docker compose commands.
#
# Every target here was run for real while this file was written (see
# docs/handoff/001-docker-topology.md "How to verify"). Nothing aspirational.

SERVICE ?= app

.PHONY: up down build logs shell test lint fresh

## Start the full stack in the background.
up:
	docker compose up -d

## Stop and remove containers (volumes are kept).
down:
	docker compose down

## Build (or rebuild) every image.
build:
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
	docker build -f docker/php/Dockerfile --target test -t taxos-php-test:local .
	docker run --rm -e APP_KEY=$${APP_KEY:-base64:0000000000000000000000000000000000000000=} taxos-php-test:local vendor/bin/pest
	docker build -f docker/python/Dockerfile --target test -t taxos-python-test:local .
	docker run --rm taxos-python-test:local pytest

## Run the PHP (Pint + PHPStan) and Python (ruff + pyright) linters.
lint:
	docker build -f docker/php/Dockerfile --target test -t taxos-php-test:local .
	docker run --rm taxos-php-test:local vendor/bin/pint --test
	docker run --rm -e APP_KEY=base64:0000000000000000000000000000000000000000= taxos-php-test:local vendor/bin/phpstan analyse
	docker build -f docker/python/Dockerfile --target test -t taxos-python-test:local .
	docker run --rm taxos-python-test:local ruff check src tests
	docker run --rm taxos-python-test:local pyright

## Everything that must pass before a change is done, run against the ALREADY RUNNING stack.
##
## Distinct from `make test`/`make lint`, which build dedicated test images from scratch — correct
## for CI, far too slow to run after every edit. This is what to use while working.
check: check-js check-php

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
check-php:
	cd apps/api && vendor/bin/pint
	docker compose exec -T $(SERVICE) vendor/bin/pint --test
	docker compose exec -T $(SERVICE) vendor/bin/phpstan analyse --no-progress --memory-limit=1G
	docker compose exec -T $(SERVICE) vendor/bin/deptrac --no-progress
	docker compose exec -T $(SERVICE) sh -c 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: vendor/bin/pest'

## The suites sqlite cannot run: the audit chain's `SELECT … FOR UPDATE`, `ilike`, `count(*) FILTER`.
##
## Points at `taxos_test`, never the development database. `Tests\TestCase` refuses anything else —
## the suite runs `migrate:fresh`, and a run against `taxos` destroys every row in it.
check-php-pg:
	docker compose exec -T $(SERVICE) sh -c 'APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE=taxos_test vendor/bin/pest'

## Apply .env or compose changes to the running stack.
##
## PHP edits no longer need this: `docker/php/php.dev.ini`, mounted only by compose.override.yaml,
## turns `opcache.validate_timestamps` back on in development, so php-fpm picks up an edited file on
## the next request exactly like a normal Laravel install. Production keeps it off.
##
## Still required when a container's *environment* changes, because compose reads `.env` only when
## it creates a container. If the variable is shared with another service (REDIS_PASSWORD is), name
## that service here too, or the two come up disagreeing.
reload:
	docker compose up -d --force-recreate $(SERVICE)

## Is the site actually up? Answers the question a green test suite does not.
smoke:
	@curl -sk -o /dev/null -w 'login page: %{http_code}\n' https://127.0.0.1/login
	@curl -sk -o /dev/null -w 'settings api (expect 401 when signed out): %{http_code}\n' \
		-H 'Accept: application/json' https://127.0.0.1/api/v1/settings

## Destroy everything, including volumes, and start clean.
fresh:
	docker compose down -v
	docker compose build
	docker compose up -d
