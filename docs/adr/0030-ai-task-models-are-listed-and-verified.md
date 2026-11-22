# ADR 0030 — The AI tasks and their models are listed, and checked against the provider

**Status:** accepted, 2026-11-22

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

The settings AI card gains a read-only list of every AI task and the model it will use, and a button
that asks the provider whether those models exist.

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
  it reached). Forgetting the row means the task is absent from the screen — a visible omission, not
  a wrong answer.
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

## Sources consulted

ADR 0007 (provider-agnostic AI, the one client); ADR 0021 (per-task models for statement reading);
`config/ai/providers.php`, `config/ai/statements.php`, `config/ai/idcard.php`,
`config/ai/legislation.php`, `config/ai/embeddings.php`; `ResolveAiProviderCredentials`,
`FetchAiProviderModels`, `AiProviderFailure`; `routes/settings.php` (the view/manage permission
split and the throttle on provider-reaching routes).
