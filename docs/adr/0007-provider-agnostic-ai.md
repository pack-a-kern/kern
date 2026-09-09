# ADR 0007 — Provider-agnostic AI via the OpenAI-compatible wire protocol

**Status:** accepted, 2026-09-07

## Context

`BLUEPRINT.md` §1.2 calls for a "provider abstraction for LLM/embeddings" without specifying its
shape. Two later requirements constrain the design: §14.4's AI extraction must return strict,
schema-validated JSON with no "best effort" partial saves, and §6.6's `document_extractions` table
records `model_provider`/`model_name` per result — the schema already assumes results can come
from more than one model, and that provenance is tracked per result, not per deployment.

The wrong way to satisfy "provider-agnostic" is a class hierarchy per vendor SDK (an
`AnthropicClient`, an `OpenAIClient`, a `LlamaClient`, each with its own request/response shapes,
its own auth mechanism, its own retry/streaming semantics) unified behind a hand-rolled interface.
That is exactly the "generic factory/manager built to avoid a little duplication" pattern
`docs/CODE-QUALITY.md` §1 warns against, and — separately — it means importing and maintaining a
distinct SDK dependency per vendor, which is a standing dependency-surface cost
(`docs/DEPENDENCY-POLICY.md` §1/§4: every dependency is pinned, audited, and justified) for
something that does not need it.

## Decision

1. **The abstraction is the OpenAI-compatible chat-completions wire protocol, not a class
   hierarchy.** Anthropic, OpenAI, and any hosted-Llama provider that speaks this
   protocol are reached through the same HTTP request/response shape.
2. Provider selection is **configuration, not code**: `AI_PROVIDER`, `AI_BASE_URL`, `AI_MODEL`,
   `AI_API_KEY`. Swapping providers is an environment change, never a deploy of new client code.
3. A **thin per-provider adapter** exists for exactly one thing the wire protocol does not
   standardize: structured-output enforcement. Where a provider offers native JSON-schema-
   constrained output, the adapter uses it; where it doesn't, the adapter does prompt + parse +
   validate + retry-on-invalid. Everything downstream of that — schema validation itself
   (§14.4), rejecting invalid output as a failure rather than a best-effort save (§14.6,
   `docs/AGENTS.md` rule 14) — is provider-independent code that never branches on which vendor
   answered.
4. `document_extractions.model_provider` / `.model_name` (§6.6) are populated from the resolved
   config at call time, giving per-result provenance without the provenance logic needing to know
   anything vendor-specific.
5. Local/hosted Llama is a **supported configuration** of the same mechanism (any endpoint that
   speaks the OpenAI-compatible protocol qualifies) — it is not a separately engineered path.

## Consequences

**Good:**
- Switching from one provider to another touches four environment variables, not
  application code — directly useful for provider outages, pricing changes, or a future
  region/data-residency requirement (relevant given ADR 0005's EU-processor requirement for
  external document AI).
- **This is a dependency-surface decision as much as a portability one.** Standardizing on one
  wire protocol means the codebase carries **one HTTP client**, not one vendor SDK per provider.
  Each vendor SDK would be its own pinned, audited, license-checked, monthly-reviewed dependency
  under `docs/DEPENDENCY-POLICY.md` — its own CVE surface, its own breaking-change risk on
  upgrade, its own justification paragraph in a handoff note (`docs/DEPENDENCY-POLICY.md` §4).
  Multiplying that by every provider the project might ever want is exactly the dependency growth
  the policy exists to prevent, for a benefit (slightly more idiomatic per-vendor calls) that
  the abstraction doesn't need. Adding a vendor SDK to reach a provider that already speaks the
  OpenAI-compatible protocol is not permitted under this ADR without a documented reason the wire
  protocol cannot satisfy.
- Structured-output validation being provider-independent means §14.4's "invalid schema is a
  failure, not best-effort" rule is enforced once, centrally — a provider cannot special-case its
  way around it by returning something that merely looks like the requested schema.
- The adapter layer is intentionally small (structured-output enforcement only), which keeps it
  within `docs/CODE-QUALITY.md` §1's line: real, stable variation (how each provider signals
  schema-constrained output) is abstracted; nothing else is, because nothing else varies enough to
  earn it.

**Bad:**
- The OpenAI-compatible protocol is a de facto standard, not a specification any provider is
  contractually bound to keep matching — a provider changing its compatible endpoint's behavior
  breaks this project with no formal contract to point to, similar in kind (though lower in
  stakes) to ADR 0003's ANAF-portal risk.
- Providers differ in real ways the shared protocol does not surface: context window, function-
  calling nuances, rate limits, latency, and actual output quality on Romanian-language fiscal
  text. Swapping `AI_MODEL` is operationally trivial but is not risk-free — a provider swap still
  needs the same extraction-quality validation as a first rollout, not just a config change and a
  deploy.
- Providers without native JSON-schema-constrained output push real work onto the
  prompt+parse+validate+retry path, which is slower and less reliable than native structured
  output — the adapter hides this difference in code shape, not in actual behavior or latency.
- **With no GPU available (the same constraint driving ADR 0005), a local Llama endpoint is a
  supported configuration but not a viable production path** — it would be too slow/inaccurate to
  meet the same extraction-quality bar as a hosted provider. This ADR keeps local Llama as an
  option for development/testing against the same protocol, not as a claimed production
  alternative.

## Alternatives considered

- **A class hierarchy with one client class per vendor SDK, unified behind a hand-rolled
  interface.** Rejected — multiplies dependency surface (one SDK per vendor, each independently
  pinned/audited per `docs/DEPENDENCY-POLICY.md`) for a benefit the wire-protocol approach already
  delivers, and is the kind of "abstraction to avoid duplication" `docs/CODE-QUALITY.md` §1 warns
  is more expensive than the duplication it removes.
- **Hard-code a single provider (e.g., only Anthropic) with no abstraction.** Rejected — §6.6's
  schema already tracks `model_provider`/`model_name` per result, implying the project expects
  more than one provider over its life, and ADR 0005's EU-region/DPA requirement for document AI
  may itself force a provider choice that differs from whatever is used for RAG/explanation tasks.
  A single hard-coded provider would make that split a rewrite instead of a config change.
- **A generic multi-protocol abstraction layer (supporting non-OpenAI-compatible protocols too).**
  Rejected as premature — no provider currently needed by this project requires a protocol the
  OpenAI-compatible shape can't express. Building for protocols no current provider needs is
  exactly the speculative future-proofing `docs/CODE-QUALITY.md` §2 forbids.

## References

- `BLUEPRINT.md` §1.2 (provider abstraction requirement), §6.6 (`document_extractions` provenance
  fields), §14.4–§14.6 (structured extraction contracts, prompt injection, human confirmation).
- `docs/AGENTS.md` rule 14 (LLM output is never truth without schema validation).
- `docs/CODE-QUALITY.md` §1 (no abstraction to avoid a little duplication), §2 (no speculative
  future-proofing).
- `docs/DEPENDENCY-POLICY.md` §1 (pin everything), §4 (every dependency justified in the handoff
  note) — the basis for the dependency-surface argument above.
- ADR 0005 (external document AI) — the EU-region/DPA requirement that may drive a provider
  choice for document processing distinct from the provider used elsewhere.
