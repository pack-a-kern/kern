# ADR 0013 — A system encryption scope and a minimal admin gate for runtime settings

**Status:** accepted, 2026-09-09

## Context

`docs/handoff/041-settings-module.md` builds a runtime settings store so the AI document-extraction
provider can be changed from a settings screen instead of an env var + container restart. Two
design questions have no existing answer in this codebase:

1. **Encryption.** `BLUEPRINT.md` §4.2's envelope encryption is defined in terms of a per-`TaxSubject`
   DEK: every encrypted field's AAD is `subject_id | table | field | record_uuid`
   (`App\Support\Crypto\AssociatedData`), and the only `SubjectDekRepository` implementation in
   this codebase (`Kern\TaxRomania\Subjects\Infrastructure\EloquentSubjectDekRepository`) looks a
   subject id up in `tax_subjects.id`/`users.id`, both native Postgres `uuid`-typed columns. A
   system-wide setting (one API key for the whole deployment, per the task's explicit decision)
   has no `TaxSubject`. Storing it in a `TaxSubject`- or `User`-scoped ciphertext would misuse the
   AAD's own meaning; inventing a fake UUID and pretending it names a real subject would violate
   `docs/AGENTS.md` rule 1's spirit ("do not invent a fake subject id") and would make a genuinely
   different kind of ciphertext indistinguishable, at the schema level, from a real tenant's.
2. **Authorization.** Changing the AI provider decides where taxpayer documents are sent — an
   administrative, data-protection-relevant act. `BLUEPRINT.md` §5.3 defines roles
   (OWNER/ADMIN/ACCOUNTANT/VIEWER, `Kern\TaxRomania\Subjects\Enums\MembershipRole`) only against a
   `subject_memberships` row, i.e. only ever *for a specific `TaxSubject`*. There is no concept
   anywhere in this codebase of a user being "an administrator" independent of any subject.

## Decision

### 1. A dedicated, non-tenant encryption scope

`Kern\Platform\Settings\Support\SystemEncryptionScope::ID` is the literal string `'system'`, used as
the `subject_id` segment of `AssociatedData` for every secret setting. This is not a UUID and can
therefore never collide with a genuine `tax_subjects.id`/`users.id` — it is immediately
recognisable, in a raw database dump or in code, as "not a tenant." This exact pattern already
exists once in this codebase for an identical reason:
`Kern\TaxRomania\Audit\Services\AuditChainService::SYSTEM_CHAIN_KEY = 'system'` routes audit events with
no subject to their own chain. ADR 0013 generalises the same idea to encryption.

This scope has its **own** DEK, wrapped and persisted in a new `system_encryption_keys` table
(`scope` primary key, `encrypted_dek`/`dek_kek_version` columns — the same shape
`tax_subjects`/`users` already use), read/written by a new `Kern\Platform\Settings\Infrastructure\
SystemDekRepository`. This is a **second, independent instance** of the existing
`App\Support\KeyManagement\SubjectDekService` class — reused verbatim, not reimplemented — wired up
in `Kern\Platform\Settings\SettingsServiceProvider` with `SystemDekRepository` instead of the
container's shared `EloquentSubjectDekRepository`. Two consequences fall out of this design, both
required by the task brief:

- **A system-scoped ciphertext cannot be decrypted as a subject-scoped one, or vice versa, for two
  independent reasons layered on top of each other**: the DEK itself differs (a real tax subject's
  DEK is never involved in sealing a system-scope secret, and the system DEK is never involved in
  sealing a tenant's field), *and* the AAD's leading segment differs (`'system'` vs. a real UUID),
  which `sodium_crypto_aead_xchacha20poly1305_ietf_decrypt` authenticates and rejects on mismatch
  even in the hypothetical case the same key were ever reused. Proven both ways round in
  `tests/Unit/Platform/Settings/SystemEncryptionScopeIsolationTest.php` against the real
  `EnvelopeCipher`, not a stand-in.
- No change was needed to `App\Support\Crypto\EnvelopeCipher`, `AssociatedData`, or
  `App\Support\KeyManagement\SubjectDekService` — all three already accept a plain `string` for
  "which principal," with no built-in assumption that it names a `TaxSubject` specifically. The
  only new code is a second table, a second `SubjectDekRepository` implementation, and a thin
  `Kern\Platform\Settings\Support\SystemSecretCipher` wrapper — see `docs/handoff/041`.

### 2. A minimal, deploy-time admin allowlist — not a new RBAC system

`config('settings.admin_user_ids')` (env `SETTINGS_ADMIN_USER_IDS`, comma-separated `users.id`
values) is the entire authorization model for `/api/v1/settings/**`
(`Kern\Platform\Settings\Authorization\SettingsAdministrators`,
`App\Http\Middleware\EnsureSettingsAdmin`). Rejected alternatives:

- **A `subject_memberships` role check** (e.g. "OWNER on any subject"). Rejected: that table's
  entire meaning is "this user may act on *this* `TaxSubject`" — a system-wide setting has no
  subject to check membership against, and stretching the existing role concept to mean something
  it was never scoped for would corrupt that table's contract for every other reader of it
  (`docs/AGENTS.md` §6).
- **A new `is_system_admin` column on `users`.** Considered and rejected for this task: it would
  require a migration on a table `Domain/Identity` owns (out of this task's declared scope,
  `apps/api/packages/platform/src/Settings/**`) and a self-service or admin-tooling path to flip it that does
  not exist and was not asked for. A config-driven allowlist needs neither.
- **A full RBAC system** (roles, permissions, a `system_roles` table). Explicitly out of scope per
  the task brief ("do not build a full RBAC system") and unjustified for gating exactly one
  administrative surface.

The allowlist is deliberately **not** editable through the API this task builds — granting "can
decide where taxpayer documents are sent" is itself a decision with data-protection consequences,
so it stays an operational/deploy-time step (edit the env var, redeploy), not a self-service action
reachable by whoever currently holds admin access. This is the one place in this task's design
that *does* still require a restart — a deliberate, narrow exception to the "no restart" goal,
which applies to the AI provider configuration itself, not to who may change it.

## Consequences

**Good:**
- The AAD-binding guarantee `BLUEPRINT.md` §4.2 already relies on for tenant data extends to
  system-wide secrets with no new mechanism — only a new, obviously-non-tenant identifier value.
- No existing crypto-core file was touched or weakened; the whole design is additive.
- The admin gate is small enough to read and audit in one file, and does not pretend to be a
  general permissions system it is not.

**Bad:**
- Granting/revoking settings-admin access requires a deploy, unlike every other write this task
  builds. Acceptable given how rarely "who may change the AI provider" changes, and how
  security-sensitive that grant itself is.
- A user's `SETTINGS_ADMIN_USER_IDS` membership is invisible to any UI — there is no
  `GET /api/v1/settings/admins` endpoint (not asked for, and would itself need its own
  authorization story). An operator must query `SETTINGS_ADMIN_USER_IDS` directly to know who has
  access.

## References

- `BLUEPRINT.md` §4.2/§4.3/§4.10, §5.3.
- `docs/AGENTS.md` rule 1 ("do not invent a fake subject id" — the spirit this ADR's scope design
  follows, even though that rule is literally about ANAF endpoints).
- `docs/adr/0002-envelope-encryption.md` — the envelope encryption design this ADR extends, not
  replaces.
- `docs/handoff/005-crypto-core.md` — `EnvelopeCipher`/`AssociatedData`/`SubjectDekService`, reused
  verbatim.
- `docs/handoff/006-audit-chain.md` — `AuditChainService::SYSTEM_CHAIN_KEY`, the precedent this
  ADR's `SystemEncryptionScope::ID` mirrors.
- `docs/handoff/041-settings-module.md` — the task this ADR was written for.
