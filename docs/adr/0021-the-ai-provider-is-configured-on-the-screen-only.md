# ADR 0021 — The AI provider is configured on the screen only, its address and key together

**Status:** accepted
**Date:** 2026-10-19
**Amends:** [ADR 0013](0013-system-settings-scope-and-admin-gate.md) — the AI group's write path.

## Context

The AI provider's settings — whether it is on, which provider, its address, its model, its key —
could each come from the `settings` table or, when no row existed, from an `AI_*` environment
variable, field by field. And the address could be changed without the key in three places: the
general settings save, the AI-specific save (`PUT settings/ai`, which kept the stored key when none
was sent), and the connection test and model list, which took a draft address and filled in the
stored key.

The audit of 2026-10-19 (`docs/SECURITY-FINDINGS.md`, finding 10) found the test and the model list
sending the stored key to any address an administrator typed. Following it through showed the same
key could reach a new address through every one of those paths — permanently, through the saves — and
through the per-field fallback, where a saved address travelled with a key from `.env`.

## Decision

- **A key only ever goes to the address it was saved with.** The two are written together, by
  `PUT /api/v1/settings/ai/connection` and nothing else. An empty key there is "no key", never "keep the
  saved one". The general settings save refuses both (`SettingKey::isSetOnlyWithItsPair()`).
- **Nothing that calls the provider takes an address of its own.** The connection test and the model
  list use the saved pair, and refuse an address or key in the request.
- **No environment variables for any of the five.** The defaults are constants — off, nothing set —
  and the resolver takes the key from wherever the address came from.
- **The address has one form.** `AiProviderAddress`: `http(s)`, a host, an optional port, an optional
  plain path. A query, a fragment, credentials, `..` and percent-encoding are refused, because each
  lets the typed address choose the path the request reaches instead of the fixed one appended to it.
- **A failure says only that it failed.** Whoever presses the buttons chose the address; a reason, or
  a code standing for one, would say whether anything answers there. The reason (`AiProviderFailure`)
  goes to the log.

## Consequences

- A fresh installation has no AI until an administrator sets it up on the settings screen. The
  installer never asked about AI, so nothing it does changes.
- Setting up a provider is: save its address and key in the dialog, then load models and pick one.
- Private and internal hosts remain valid addresses — a model on the LAN or the VPN is a supported
  setup — so an administrator can still point the provider at one. With the address held to its form,
  such a request only ever reaches the fixed path, and fails.
- Showing the failure reason to an administrator who has just passed a second-factor check is a
  possible later addition; it is not part of this.

## Alternatives considered

- **Keep the overrides, but never fill in the stored key for a changed address.** Closes the test and
  the model list, not the saves or the fallback. Rejected for the narrower fix it is.
- **Keep `AI_ENABLED` alone in the environment.** Harmless for the key, but two places to look for
  whether AI is on. Rejected.
- **Reduce the address to scheme and host.** Breaks a provider served under a path
  (`https://openrouter.ai/api`). The plain-path rule closes the same hole.
- **Block `169.254.0.0/16`.** Declined: the address form already decides what can be reached, and the
  block would be the one place an address is judged by where it points rather than how it is written.

## References

- `docs/SECURITY-FINDINGS.md` finding 10
- `packages/platform/src/Http/Controllers/Settings/ReplaceAiConnectionController.php`
- `packages/platform/src/Settings/Rules/AiProviderAddress.php`
- `packages/platform/src/Settings/Services/ResolveAiProviderCredentials.php`
