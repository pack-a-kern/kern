# ADR 0030 — The AI tasks and their models are listed, and checked against the provider

**Status:** accepted, 2026-11-22; amended 2026-11-23 to make each task's model editable on the card
(see "Amendment" below)

## Context

ADR 0007 made every model reachable through one OpenAI-compatible client, and the configuration grew
around it: one base model, `ai.model`, with per-task overrides that fall back to it —
`ai.statements.mapping.model`, `ai.statements.classification.model`, `ai.idcard.read.model`,
`ai.legislation.reader` and its optional second reader. Each override lives in its own module's
`config/ai/*.php`, which is right for the module and wrong for the operator: there was no screen that
gathered them, so "which model reads a statement, and does it exist on my runtime" could only be
answered by reading three config files and then trusting that the name typed into each was one the
provider actually serves. A name that does not — `qwen3:8b` where the runtime has only `qwen3:4b` —
is not discovered until the first job fails on it, and that failure looks exactly like a model that
could not do the work.

The embedding model is deliberately outside all of this: it runs in this installation's own process
from `EMBEDDING_MODEL_ID` (`config/ai/embeddings.php`), is never asked of an external provider, and
so has nothing to choose and nothing to verify against a catalogue.

## Decision

The settings AI card gains a list of every AI task and the model it will use — editable per task
since the 2026-11-23 amendment below — and a button that asks the provider whether those models exist.

- **The task list is config, named once in the platform.** `packages/platform/config/ai/tasks.php`
  enumerates each task and the config *key* that holds its model (or null for a task that only ever
  uses the base model, or `optional` for one that is simply off when unset). It names the modules'
  config keys as **strings**, not their code: a string is not a dependency Deptrac can see, so the
  platform stays free of the modules it lists while still resolving through the one merged
  `config('ai.*')` tree. A module that renames its key updates one string here.
- **`AiTaskModels` resolves each task** to the model it will send — its own when set, otherwise the
  base `ai.model` from `ResolveAiProviderCredentials` — and marks which it is, so the screen shows an
  inherited model as inherited rather than as separately configured.
- **`GET settings/ai/task-models`** (view permission, no network) returns that list.
  **`POST settings/ai/task-models/verify`** (manage permission, throttled) makes one
  `FetchAiProviderModels` call on the *saved* connection and reports, per distinct model, whether the
  provider serves it. "Could not tell" — nothing configured, a provider that publishes no list, an
  unreachable address — is a distinct, truthful outcome (`checkable: false`), never a red cross that
  would read as "missing".
- **The check is on the button, never on open.** Listing the models is a local read and loads with
  the card; reaching the provider is an explicit act, like the connection test beside it.

## Consequences

- **One maintenance point for the task list.** A new kind of model work is a row in
  `config/ai/tasks.php` plus a label in the frontend i18n (spelled out, so the dictionary guard sees
  it reached); an *editable* task additionally needs its own `SettingKey` (with a `configPath()` to
  its env default) and to resolve through `ResolveTaskModel`. Forgetting the row means the task is
  absent from the screen — a visible omission, not a wrong answer.
- **Verification is only as good as the provider's model list.** A provider that cannot list models
  (`modelsPath` null) can never be checked; the screen says so rather than implying the models are
  missing. Reuses `FetchAiProviderModels` and its `AiProviderFailure` logging unchanged — no new way
  to reach a provider was added.
- **The platform names module config keys.** This is the one accepted cross-module reference, and it
  is by string in config, not by import in code. The alternative — a runtime registry each module
  feeds in its service provider — was heavier for no boundary gain, since the keys already share one
  config namespace.
- **The embedding model stays invisible here by design**, matching `config/ai/embeddings.php`'s own
  note that there is no `model` key to choose.

## Amendment (2026-11-23) — each task's model is editable, not only listed

The list was read-only: a per-task model could only be changed by editing a module's env var and
redeploying, exactly the friction the base model had already shed by living in the settings database.
This amendment extends that same pattern to the tasks, with no change to the resolution rules above.

- **Each editable task has its own `SettingKey`** (`AI_STATEMENT_MAPPING_MODEL`,
  `AI_STATEMENT_CLASSIFICATION_MODEL`, `AI_ID_CARD_MODEL`, `AI_LAW_READER_MODEL`,
  `AI_LAW_SECOND_READER_MODEL`), in the AI group, of type `STRING`. Its `configPath()` is the module
  config key the task already read from env — so the key *bridges* to the env default rather than
  replacing it. `document_read` has no key: it follows the base model, as before. `tasks.php` now
  names these `setting_key`s instead of raw config keys (still platform's own strings — Deptrac-clean).
- **`ResolveTaskModel` is the one place that applies the precedence**: the saved override, else the
  task's env/config default, else nothing. `AiTaskModels` (the screen) *and* every service that runs a
  task — the statement reader and classifier, the identity-card reader, the law reader — resolve
  through it. Previously each read `config()` directly, so an override would have shown on the screen
  and never reached the work; routing them all through one resolver is what keeps the two in step.
- **Editing reuses the existing group machinery.** The keys save through `PUT settings/group/ai` like
  every other AI field: a model name is stored as an override, an empty field (`null`) clears it and
  the env default returns. Provenance (`SettingSource`) marks each row as inherited or overridden.
- **Env stays the seed, the database holds only overrides.** An installation that never edits a task
  behaves exactly as before — the env value resolves through `configPath()`. `.env` is never written;
  the app keeps no write access to the secrets file.

## Sources consulted

ADR 0007 (provider-agnostic AI, the one client); ADR 0021 (per-task models for statement reading);
`config/ai/providers.php`, `config/ai/statements.php`, `config/ai/idcard.php`,
`config/ai/legislation.php`, `config/ai/embeddings.php`; `ResolveAiProviderCredentials`,
`FetchAiProviderModels`, `AiProviderFailure`; `routes/settings.php` (the view/manage permission
split and the throttle on provider-reaching routes).
