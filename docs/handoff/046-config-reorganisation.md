# 046 — Reorganising configuration

A pass over all 35 files in `apps/api/config`, done in six phases, each its own commit and each
verified by the guard test of phase 1.

## What was found

| # | Finding |
|---|---|
| 1 | **AI settings in five places.** `ai_providers.php` (presets, the document prompt, timeouts), `settings.php` (whose only content is `ai.env_fallback`), `statement_import.php` (two models, token limits, queue, four prompts), `retrieval.php` (the embedding model) and `horizon.php` (the model queue's timeout). Tuning one model meant knowing all five. |
| 2 | **The Python service described three times.** `documents.text_extraction`, `retrieval.embedding` and `statement_import.parser` each carry `http://python-api:8000` under a different env var, and the same `INTERNAL_SERVICE_AUTH_TOKEN`. A moved service needs three edits, and the health probe checks only one of the three addresses. |
| 3 | **Settings mixed with facts.** `countries`, `counties`, `banks`, `party_names`, `tax_categories`, `sources.entries` and `statement_import.months` read no env var and are not settings: they are data about the world. |
| 4 | **Timeouts and waits written into code.** See the table below. |
| 5 | **72 app-owned env vars documented nowhere**, and two `.env.example` files whose roles were unclear — see phase 6. |
| — | Healthy: every literal config key the code reads exists, and no application code calls `env()`. |

### Timeouts and waits in code

| Where | Value | Becomes |
|---|---|---|
| `config/horizon.php`, `default` supervisor | `timeout => 60` | `HORIZON_DEFAULT_QUEUE_TIMEOUT_SECONDS` |
| `ReadStatementFile` | wait 15 s between checks, 20 checks | `statement_import.scan_wait.seconds_between_checks`, `.checks` |
| `ProposeCategoryBatch`, `ReadStatementFile`, `ProcessScannedDocument` | `TIMEOUT_MARGIN_SECONDS = 60`, three copies | `ai_providers.job_timeout_margin_seconds` (`AI_JOB_TIMEOUT_MARGIN_SECONDS`) |
| `ProbeSystemHealth` | 3 s | `operations.health.timeout_seconds` |
| `LoginController` | pending second factor valid 300 s | `identity.mfa.pending_ttl_seconds` |
| `HttpEmbeddingProvider` | fallbacks `900`, `32` and `1024` — the last contradicting config's `384` | removed; a missing value is refused, naming the key |
| `AiProviderCatalogue` | fallbacks `10` and `120` repeating config's defaults | removed — config is the default |
| Redis `retry_after` | 90 s for every queue, while the model queue allows 900 s | the model supervisor reads through its own connection, `queue.connections.redis-ai`, whose reservation outlasts its timeout |

The last row was safe only by accident: a reservation that outlives `retry_after` is handed out again,
but only to a process working the same queue — and the model queue has one, busy with the call. A
second process would have made every call over 90 seconds run twice.

Deliberately left in code: the 400 ms preview debounce (interface behaviour, not an operating
limit), the 2 s grace period for reaping a killed PDF process, and `$tries = 1` on model calls,
which is a decision — a model call is never retried silently — rather than a tunable.

## What cannot move

Laravel and its packages look their own files up by top-level name, so these stay where they are:
`app auth broadcasting cache database filesystems hashing logging mail queue services session
horizon reverb permission webauthn`.

Folders are supported: `LoadConfiguration` walks `config/` recursively and turns each directory into
a dotted prefix, so `config/ai/providers.php` is read as `config('ai.providers.…')`. Env var names do
not change when a file moves; only the keys the code uses do.

## Phases

1. **Guard test.** Reads the source for every literal config key and fails naming any that does not
   resolve. A key that does not exist returns null silently, so without this a moved file breaks
   nothing loudly.
2. **Timeouts into config.** The table above.
3. **`services.processing`.** The Python service's address and token, once. Each feature keeps its
   own timeout, because reading a PDF and embedding a law genuinely take different times. The health
   probe checks the address all three use.
4. **`config/ai/`.** `providers.php`, `documents.php`, `statements.php`, `embeddings.php` — every
   model, prompt, token limit and model timeout in one folder.
   Old key → new key:

   | Was | Now |
   |---|---|
   | `ai_providers.presets`, `.anthropic_version`, `.supports_enhancement`, `.timeout_seconds`, `.job_timeout_margin_seconds` | `ai.providers.*` |
   | `settings.ai.env_fallback.*` (the whole of `settings.php`) | `ai.providers.env_fallback.*` |
   | `statement_import.ai.queue` | `ai.providers.queue` (`AI_QUEUE`, also named by `horizon.php`) |
   | `ai_providers.prompt.*` | `ai.documents.prompt.*` |
   | `ai_providers.extraction_timeout_seconds`, `_max_image_bytes`, `_max_tokens` | `ai.documents.timeout_seconds`, `.max_image_bytes`, `.max_tokens` |
   | `statement_import.ai.model`, `.max_tokens`, `prompts.mapping_*` | `ai.statements.mapping.model`, `.max_tokens`, `.system`, `.instruction` |
   | `statement_import.ai.classification_*`, `prompts.classification_*` | `ai.statements.classification.model`, `.max_tokens`, `.batch`, `.system`, `.instruction` |
   | `statement_import.ai.timeout_seconds` | `ai.statements.timeout_seconds` |
   | `retrieval.embedding.*` (the whole of `retrieval.php`) | `ai.embeddings.*` |

   Every value was compared before the old files were deleted, and was identical. Env var names are
   unchanged except `STATEMENT_IMPORT_AI_QUEUE`, which became `AI_QUEUE` when the queue stopped
   belonging to one feature. `ExtractionPrompt` no longer falls back to prompt wording written in the
   class: a missing prompt is a named configuration error. The guard test now also checks every
   `SettingKey::configPath()`, which the source scan cannot see.
5. **`config/reference/`.** `countries`, `counties`, `party_names`, `tax_categories`, and the month
   names out of `statement_import` — read as `reference.countries.*` and so on. Narrower than first
   planned: `banks.php` and `sources.entries` stay where they are, because their filters and URLs are
   changed by env vars and by database settings, which makes them settings rather than facts. The
   four files moved with `git mv`, so their history follows them; the month names were compared
   before and after and are identical. `ImportBanks` calls `$this->filter('countries')`, which reads
   the banks config, not this file — so these renames were made call by call rather than by pattern.
6. **Env documentation.** The root `.env` reaches Laravel only through the `environment:` block of
   `compose.yaml` — a variable set there but not passed on never arrives. `apps/api/.env` is mounted
   into the container and read by Laravel for everything compose does not set. So:
   - root `.env.example` — infrastructure: secrets, ports, what compose passes in;
   - `apps/api/.env.example` — application settings, every one listed with its default, commented
     out so the default stays in one place: the config file.

Not done: a `security/` folder for `crypto`, `identity` and `access` (three small files gain nothing
from a folder), and an umbrella `taxos/` folder (it only lengthens every key).
