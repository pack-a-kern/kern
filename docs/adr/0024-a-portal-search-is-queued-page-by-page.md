# ADR 0024 — A portal search is queued, a page a job, and says how far it got

**Status:** accepted
**Date:** 2026-11-09
**Concerns:** `kern/legislation` (the search), `apps/api` (the dialog that starts one)

## Context

Finding an act on legislatie.just.ro was one HTTP request: the "Add a law" dialog called
`GET /api/v1/legislation/portal`, and the controller read every page of results before it answered.
Three things made that fail more often than it worked.

1. **The portal fails at random.** Its SOAP service answers about one call in three with HTTP 500 — the
   fault it returns is its own search engine refusing a connection (`SolrConnectionException`,
   "No connection could be made because the target machine actively refused it", 2026-11-09). Its
   search page answers about four in ten requests with its error page, the same address answering with
   its results moments later. Asked again, either answers.
2. **The browser method gave up after one refusal past the first page.** A refused second page was
   read as the end of the results, so "Programului Tezaur" answered 20 orders of 90, with nothing to
   say they were not all.
3. **A request has a ceiling.** PHP stops it at 60 seconds and Cloudflare in front at about 100. A search
   that retries its pages, a browser per page, can need more; it was cut off and reported as a portal
   that did not answer.

The search is also about to be needed by something that is not a person at a screen: the government
bond package will read the Ministry of Finance's Tezaur and Fidelis orders from the same portal, with
no browser tab to keep a request open.

What was learned about the portal on the way, and is now in the configuration rather than in anyone's
memory:

- Its search page lists as many results a page as its `rezultatePerPagina` code asks — `5` is fifty — so
  "Programului Tezaur" is two pages there, not nine.
- Its SOAP service answers ten a page whatever `RezultatePagina` says above ten, and ignores `SearchAn`
  beside a title. It is the slower and the less reliable of the two.
- Neither says how many results there are. Reading stops at a page shorter than a full one, at a page
  that adds nothing new, or at the configured maximum.
- The site's error page means nothing about the search. A search with no result, and a page past the
  last, are answered with an empty list — and with the error page at random, like any other request
  (measured 2026-11-09). The code used to read a refused page as "nothing matched" or as the end of the
  results; both were wrong.

## Decision

**A search is a record, read a page at a time by queued jobs.**

- `POST /api/v1/legislation/portal-searches` validates the query (words of a title, or a number and a
  year; a year; a type), stores it as an `ext_portal_searches` row owned by whoever asked, queues the job
  for its first page, and answers `202` with the record at once. It never waits for the portal.
- `ReadPortalSearchPage` reads one page through `PortalSearch::page()`, adds the acts it has not seen to
  the record under a row lock, and queues the next page unless this one was the last. The method first
  asks a failing page again a few seconds apart (`sources.entries.LEGISLATIE.search_refused_*`, five tries
  by default); a page still failing — the first or any other — is retried by the queue after the pauses in
  `legislation.portal_search.retry_seconds`. One that still fails ends the search as **incomplete** at that
  page — or **failed**, if it was the first — with the reason. A search no longer stops quietly short of
  its results, and `PortalSearch::all()`, which reads every page at once, throws rather than answer part.
- `GET …/portal-searches/{id}` answers the record: its status, how many pages were read, why it stopped,
  and the acts, each already shaped for the screen — whether it can be read, and which law it already is.
  `DELETE …/portal-searches/{id}` cancels it; the next page's job finds it cancelled and reads nothing.
- Each change fires `PortalSearchChanged`, broadcast on the asker's own channel (`users.{id}`, set by
  `legislation.portal_search.broadcast_channel`). It carries the id and the counts, never the acts: the
  screen fetches them over HTTPS, through the same authorisation as any other read.
- Starting a search cancels the asker's searches still running — a person looks for one act at a time —
  and removes every search older than `legislation.portal_search.keep_hours`, whoever started it, so the
  table holds what someone may still be reading and needs no schedule of its own.

**One page is one method call.** `PortalSearchMethod` answers `page(PortalQuery, int $page)`; the
browser and the service each know their own page size and what a refused page looks like. `PortalSearch`
keeps what matches the query — the number and year, the year of a title search, the type — the same way
whichever method was asked, and its `byTitle()`, `byNumber()` and `find()` read every page in a loop for
whoever can wait: the import's own look-up, a console command, another package.

**Every number is configuration.** The site's page-size code, the pages to read, the pauses, the tries,
the queue, how long a record is kept, how soon the screen asks again — `config/sources.php`,
`config/playbooks/legislatie.php` and `config/legislation.php`. Nothing about the portal is a literal in
the code.

## Consequences

- The screen shows a search as it goes — the pages read, the acts so far — and can stop it. A search that
  needs three minutes takes three minutes, and nothing times out.
- A search costs a row and a few small jobs. It is gone after `keep_hours`; it holds public data only —
  titles from the Official Gazette — and the id of whoever asked, which goes with the account.
- Something other than a person can search: the bond package starts a record without an owner (nothing
  is broadcast then) or calls `PortalSearch::byTitle()` directly from a job of its own.
- `GET /api/v1/legislation/portal` is gone, with its controller. Nothing else called it.
- The browser now opens a browser per page rather than one for the whole search: a few seconds a page,
  against a page that can be retried on its own.

## Not done

- **Reading the total.** Neither the site nor the service gives one; the reading stops by the rules
  above. If the results page is found to print a count, it can end the reading exactly.
- **Asking the service for a year beside a title.** It ignores it (2026-11-09); the year is compared on
  each act, as before.
