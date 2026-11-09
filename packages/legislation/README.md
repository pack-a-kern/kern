# kern/legislation

Read a law from the source that publishes it, and keep every version of it you were ever shown.

Built for the question "what did this article say on 14 March 2023, and who says so" — which is
answerable only if the whole history is kept, dated, and traceable to bytes that were fetched once
and never rewritten.

## What it does

- **Fetches** only from an allowlist (`config/sources.php`): a host, a scheme, a rate limit and a
  size cap per source. Anything else is refused before a connection opens.
- **Stores** the raw bytes immutably, hashed, in the object store — the evidence, kept whether or not
  the parse succeeded.
- **Consolidates**: a law's every consolidation is dated against the next, so a section carries the
  window it was in force for and no day falls in two versions.
- **Parses** structure — book, title, chapter, article, paragraph, letter — into sections with
  citations, plus an outline and a per-provision history.
- **Searches** it: lexical over Postgres' Romanian text configuration and semantic over pgvector, in
  one query, filtered by the date you are asking about.

## Finding an act on the portal

`Legislatie\PortalSearch` finds acts on legislatie.just.ro, through its SOAP service or its search page
in the browser worker, as `sources.entries.LEGISLATIE.search` says (ADR 0024).

- **To wait for the answer** — a job, a console command: `byTitle('Programului Tezaur', 2026)`,
  `byNumber(4, 2021)`, `find('HOTARARE', 4, 2021)`, or `all(PortalQuery::byTitle(...))`. Every page is
  read; a page the portal never answers is thrown, never left out quietly — the site's error page as
  `PortalSearchRefusedException`, the browser as `PortalBrowserException`, anything else as it came.
  `Enums\PortalSearchFailure::of()` names the reason.
- **To follow it as it goes** — a screen: `Actions\StartPortalSearch` stores the query as a
  `PortalSearchRun` and queues `Jobs\ReadPortalSearchPage`, a page a job, each retried by the queue.
  The run ends `done`, `incomplete` (with the pages read) or `failed`, saying why; `PortalSearchChanged`
  is broadcast on the owner's channel after each page. Over HTTP: `POST`, `GET` and `DELETE`
  `/api/v1/legislation/portal-searches`. A run with no owner broadcasts nothing.

Every number — page sizes, pages read, tries and pauses, the queue, how long a run is kept — is in
`config/sources.php`, `config/playbooks/legislatie.php` and `config/legislation.php` (`portal_search`).

## What it is not

It has no idea what a tax is. It knows a source, a document, its versions, its sections and their
history; `TaxRule` here is a rule of *some* law, traced to the sections it rests on. What a value
*means* for somebody's return belongs to whoever computes returns.

That boundary was enforced by taking one line out: the sync used to dispatch the job that reads the
minimum wage out of a government decision. It now fires `Events\LawConsolidationsRead` and says which
document it finished. Whoever cares that this particular decision sets a wage listens for it.

## Installing

```json
"require": { "kern/legislation": "^1.0" }
```

Migrations, routes, commands and config arrive with it. It needs an object-store disk, Postgres with
`pgvector`, `pg_trgm` and `unaccent`, and — for semantic search — an embedding endpoint;
`ai.embeddings.enabled` is false by default, and search stays lexical until it is turned on rather
than failing halfway through a backfill.

```
php artisan kern:legislation:sync        # look for new consolidations of every law already read
php artisan kern:legislation:reindex     # rebuild sections and chunks from the stored bytes
```

## Running its own suite

```
composer install
vendor/bin/pest
```

`tests/Unit` is this package's classes as plain PHP — no container, no database, milliseconds.
`tests/Integration` boots Laravel with this package and its dependencies and nothing else, which is
the only way to find out whether it is actually installable.
