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

## Watching for new acts

A law already read is kept up to date by the sync, which reads its new consolidations. A **new act** —
this year's minimum-wage decision, a new order of a ministry — is a different document, and nobody
updates it: it has to be found. A **law watch** finds it: a search of the portal run again on its own
clock, each new act it finds read as a law, the way the "Add a law" dialog reads one.

Watches live in `ext_law_watches`. The hourly task `legislation.watch` (`php artisan kern:legislation:watch`)
runs only those due:

- **its first run** searches every year from the watch's first; **each later run** this year, and last
  year too while this one is younger than `LEGISLATION_WATCHES_OVERLAP_DAYS`;
- an act already in the library is skipped; a new one is read as a law, and when that finishes
  `LawConsolidationsRead` fires — whoever watches for it reads what it needs from it then;
- each runs under a lock of its own (`LEGISLATION_WATCHES_LOCK_SECONDS`) and fails alone: a run that
  failed, all or in part, is tried again the next hour, and a first run that failed still searches every
  year next time;
- its last result is kept and shown on the laws screen.

There are two kinds.

### A package's watch

Declared by the package that needs the acts, in its own configuration, and mirrored into the table at
every run: a new one is added, a changed one takes its new search, one no longer declared — the package
removed — is removed. It keeps its own interval; on the screen it can be paused, not deleted.

A package declares its watch in a `config/watches.php` of its own — every value from its environment, no
default — and adds it from its service provider once every provider has registered. With made-up names:

```php
// packages/example-reports/config/watches.php
return [
    'example-reports.circulars' => [
        'declared_by' => 'kern/example-reports',
        'search' => env('EXAMPLE_CIRCULARS_SEARCH'),            // words of the title, as the portal searches them
        'act_type' => env('EXAMPLE_CIRCULARS_ACT_TYPE'),        // the portal's type key, e.g. CIRCULARA
        'title_pattern' => env('EXAMPLE_CIRCULARS_TITLE'),      // a PCRE pattern a title must match, or null for all
        'first_year' => env('EXAMPLE_CIRCULARS_FIRST_YEAR'),    // the first year searched
        'every_hours' => env('EXAMPLE_CIRCULARS_WATCH_HOURS'),  // how often it runs
    ],
];
```

```php
// ExampleReportsServiceProvider::register()
$this->app->booting(static function (Application $app): void {
    // Merged in whole: a watch's key has a dot in it, which `set()` would read as one level deeper.
    $config = $app->make('config');
    $config->set('legislation.watches.declared', [...$config->array('legislation.watches.declared', []), ...require __DIR__ . '/../config/watches.php']);
});

// …and, to read what it watches for once it is read:
Event::listen(LawConsolidationsRead::class, [ReadExampleCirculars::class, 'handle']);
```

```dotenv
# packages/example-reports/.env.example
EXAMPLE_CIRCULARS_SEARCH="circulara privind exemplul"
EXAMPLE_CIRCULARS_ACT_TYPE=CIRCULARA
EXAMPLE_CIRCULARS_TITLE="/privind exemplul/u"
EXAMPLE_CIRCULARS_FIRST_YEAR=2024
EXAMPLE_CIRCULARS_WATCH_HOURS=168
```

The key is the package's name and the watch's, so two packages never share one. A value missing or wrong
fails the run, naming the watch and pointing at the declaring package's `.env.example`.

Watches declared today: `kern/tax-romania`'s minimum-wage decisions, `kern/investments`' Fidelis and
Tezaur orders.

### A person's watch

Kept from the "Add a law" dialog — **Watch this search** — for the words and act type searched, every
year from the one given, at one of the intervals `LEGISLATION_WATCHES_INTERVALS` offers. It keeps every
act its search finds, at most `LEGISLATION_WATCHES_MAX_NEW_ACTS` new ones a run — the rest at the next
run, within the hour. A person keeps `LEGISLATION_WATCHES_MAX_PER_PERSON` watches at most. Theirs alone to
change or delete (anyone else gets 403); it goes with their account.

| Call (`/api/v1/legislation/watches`, `operations.run`) | Does |
|---|---|
| `GET /` | Every watch, the packages' first, and the intervals a person may choose |
| `POST /` `{search_words, act_type, first_year, every_hours}` | Keeps a search — audited; past the cap, `TOO_MANY_WATCHES` |
| `PATCH /{id}` `{enabled?, every_hours?}` | Pauses or resumes — audited; a person's own, by that person, also runs more or less often |
| `DELETE /{id}` | Removes a person's watch — audited; a package's is refused (`WATCH_IS_DECLARED`) |

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
