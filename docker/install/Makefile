# Kern — the commands a server runs. Usage: make <target>
#
# This file is two things at once. On a server installed from a release it is the whole Makefile:
# docker/install/Dockerfile ships it as the release's `Makefile`, so a server has these targets and no
# others — every one of them works without the source code. In the repository it is included by the
# root Makefile, which adds the development targets (env, build, test, lint, check…) around it.
#
# Every target here was run for real while this file was written (see
# docs/handoff/001-docker-topology.md "How to verify"). Nothing aspirational.

SERVICE ?= app

.PHONY: compose-file install tls-ca up update down logs shell reload smoke migrate

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

## Install this on a server: ask the questions, generate every secret, bring the stack up.
##
## One command from a release's files (or a clone) to a working installation — the environment file, the TLS
## certificate, the frontend assets, the images, the migrations, the role catalogue, the first
## administrator, and a health check that fails the install if something it needs did not answer.
## It refuses to overwrite an existing apps/api/.env; `make install ARGS=--repair` re-runs everything
## after that file against an installation that is already there.
##
## On a development machine, `make env` is the other way in (the repository's own Makefile): it writes
## the same file from .env.example with every value still to fill in, and the dev shape of COMPOSE_FILE.
install:
	@scripts/install.sh $(ARGS)

## Write this installation's CA certificate to kern-ca.crt. Usage: make tls-ca
##
## Every certificate in the stack is issued by the `certs` service from one private CA, the edge's
## included when the site is reached by IP. Import this file once into a browser or a device as a
## trusted authority and the site stops warning — renewals included, since they come from the same CA.
## The certificate only: the CA's key never leaves its volume.
tls-ca: compose-file
	@docker compose exec -T certs cat /certs/public/ca.crt > kern-ca.crt
	@echo "Wrote kern-ca.crt — import it as a trusted certificate authority where you open the site."

## Start the full stack in the background.
up: compose-file
	@test -f apps/api/.env || { echo "apps/api/.env is missing — run \`make install\` first (\`make env\` on a development machine)."; exit 1; }
	docker compose up -d

## Move this installation to a published release. Usage: make update VERSION=1.4.0
##
## For an installation made from a release's files (docs/DEPLOYMENT.md "From a release"). Fetches that
## release's files and checks CI's signature on them before one is copied in, then pulls the release's
## images and checks theirs, and changes nothing if either fails; then applies the migrations and
## recreates what changed. Going back is the same with the older version, but a migration is not
## undone by it. Every step is in scripts/update.sh.
update: compose-file
	@scripts/update.sh $(VERSION)

## Stop and remove containers (volumes are kept).
down: compose-file
	docker compose down

## Tail logs. Usage: make logs [SERVICE=queue]
logs:
	docker compose logs -f --tail=200 $(SERVICE)

## Open a shell in a running service. Usage: make shell [SERVICE=postgres]
shell:
	docker compose exec $(SERVICE) sh -lc 'exec bash || exec sh'

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

## Apply the migrations, then set the application's database role up to match.
##
## Its own container, as the database's owner — the running application connects as a role that owns
## nothing and so cannot create a table (`kern:db:grant-app-role` says why). `docker compose exec app
## php artisan migrate` therefore fails with "permission denied", by design: this is the way in.
migrate: compose-file
	docker compose run --rm migrate
