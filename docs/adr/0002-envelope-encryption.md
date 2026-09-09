# ADR 0002 — Per-subject envelope encryption + global blind-index key

**Status:** accepted, 2026-09-07

## Context

`BLUEPRINT.md` §4.1 sets an explicit threat model that includes a stolen SQL dump, a stolen
object-storage bucket, a stolen backup, and container/RCE compromise. Disk- or volume-level
encryption (§4.5) defends against a stolen physical disk, not against a stolen logical dump taken
through a live, authenticated connection — which is the far more common real-world breach. The
project needs encryption that survives the database itself being exfiltrated.

At the same time, two legitimate features need equality lookups over encrypted data without
decrypting every row: logging in by email, and detecting a duplicate CNP across the whole system
(a PF must not be able to register twice under different `TaxSubject` records). Both of these are
**inherently cross-tenant** operations — that fact has to be designed for honestly, not hidden.

## Decision

**Two distinct key classes**, not one:

1. **Per-`TaxSubject` Data Encryption Key (DEK).** Random 256-bit key, generated once per
   `TaxSubject`, never stored in plaintext. Encrypted ("wrapped") with a Key Encryption Key (KEK)
   held in a KMS/secrets-management service (OpenBao in this project — see
   `docs/reference/verified-versions.md`); the database stores only `encrypted_dek` and
   `dek_kek_version`. Sensitive fields per `BLUEPRINT.md` §4.3 (CNP, address, phone, IBAN, SPV
   username, tokens, documents, OCR output, etc.) are encrypted with the owning subject's DEK
   using XChaCha20-Poly1305 (libsodium), matching the blueprint's stated preference over
   AES-256-GCM. Every ciphertext carries algorithm, key version, nonce, and auth tag, and is bound
   by AAD to `subject_id | table | field | record_uuid` (§4.2) — moving a ciphertext to a
   different row, table, or subject must fail to decrypt.

2. **One global, versioned blind-index key**, distinct from every DEK. Used only to compute
   `HMAC-SHA-256(index_key, normalized_value)` (§4.4) for fields that must be looked up by exact
   value across the whole table: email (login), CNP (duplicate-subject detection), IBAN. This key
   is global *by necessity*: a per-subject key would make "does this CNP already exist under a
   different subject" undecidable without decrypting every subject's data, which defeats the
   purpose of a blind index entirely. The index key is versioned so it can be rotated; rotation
   requires recomputing every blind index under the new version, which is a real, scheduled
   operation, not a free one.

`php:8.5-fpm-trixie` ships `sodium` (shared, enabled) and `--with-password-argon2` out of the box
(`docs/reference/verified-versions.md`), so this ADR requires no custom PHP build — only
`pdo_pgsql`, `redis`, `bcmath`, `intl` need adding.

**This is a security-first decision, stated explicitly:** where a security control created by this
ADR conflicts with developer convenience — an extra unwrap round-trip per request, a migration
that cannot run without OpenBao reachable, a local dev setup that needs a real (if dev-mode) KMS
instead of a stubbed-out no-op, a rotation job that takes hours instead of being instantaneous —
the control wins. No agent may bypass envelope encryption, skip AAD binding, or substitute a bare
hash for a blind index because it is slower to build or annoying to test against. Where such a
conflict is discovered, the tradeoff is written down (in this ADR's Consequences, or in a new one)
rather than quietly worked around in code.

## Consequences

**Good:**
- A stolen Postgres dump or MinIO bucket alone is useless: sensitive fields and documents are
  ciphertext, and the DEKs needed to open them are themselves wrapped by a KEK the attacker does
  not have unless OpenBao is also compromised.
- Per-subject DEKs bound the blast radius of a single leaked/compromised key to one `TaxSubject`,
  and support crypto-shredding on account deletion (`BLUEPRINT.md` §25) — destroy the wrapped DEK,
  every ciphertext under that subject becomes permanently unrecoverable, without a bulk rewrite of
  the data itself.
- AAD binding defeats a specific, realistic attack: an attacker with write access copying a
  ciphertext blob from one row/table/subject to another to make it decrypt somewhere it doesn't
  belong.
- Key rotation is scoped: a compromised per-subject DEK rotates independently of the global index
  key and of every other subject's DEK.

**Bad — and this must stay documented, not quietly accepted:**
- **The global blind-index key is a deliberate, permanent weakening of tenant isolation for
  exactly the fields it indexes.** Anyone who holds that one key (an attacker who has compromised
  it, or an insider with legitimate access to the key-management path) can, without ever touching
  a DEK, test whether *any two rows across the entire system* share the same underlying email,
  CNP, or IBAN — they learn *that* two `TaxSubject`s correlate on that field, even though they
  still cannot read the field's plaintext or any other encrypted data. This is an accepted
  tradeoff because §4.4's use cases (login-by-email, duplicate-CNP detection) are inherently
  cross-tenant queries and cannot be satisfied by a per-subject key. It is not an oversight, and it
  must never be "fixed" by silently switching to per-subject index keys without re-solving login
  and dedup some other way.
- Blind indexes leak equality and, if not carefully bucketed, frequency — a low-entropy field
  indexed this way is more attackable than a high-entropy one even with a good key, which is why
  §4.4 explicitly forbids a bare `SHA256(value)`. HMAC with a secret key mitigates offline
  dictionary attack but does not eliminate the equality-correlation leak described above.
- Per-subject envelope encryption adds real operational surface: KMS/OpenBao becomes a hard
  runtime dependency for both reads and writes of sensitive fields (§23 already lists "KMS
  unavailable" as a required alert), and every migration or backfill touching an encrypted column
  needs the DEK-unwrap path available, which complicates local dev, CI, and ordinary Artisan
  tooling.
- Key rotation is not instantaneous: rotating the global index key means recomputing every blind
  index in the system under the new version before the old one can be retired, which is a
  real batch job with its own failure modes, not a config flag flip.
- Envelope encryption alone does not protect against the case §4.1 already names as
  unsolvable: a server that is fully compromised *while* actively serving decrypted data to a
  legitimate request has the plaintext in memory regardless of how it's stored at rest.

## Alternatives considered

- **Transparent database-level encryption (TDE) / disk encryption only.** Rejected as the sole
  mechanism per §4.5 — protects only against a stolen physical disk/volume, not a dump taken over
  an authenticated connection, which is the threat this ADR exists to address. Can still be layered
  on top for defense in depth; it is not a substitute.
- **One DEK per field type instead of per subject.** Rejected: does not bound blast radius to a
  tenant, and does not support per-subject crypto-shredding on deletion.
- **Per-subject blind-index keys.** Rejected as unworkable for the two use cases that actually
  need blind indexes (login-by-email, duplicate-CNP), both of which are cross-tenant by
  definition. A single global index key was chosen instead, with the cross-tenant correlation
  risk documented above rather than hidden.
- **AES-256-GCM instead of XChaCha20-Poly1305.** Not rejected outright — §4.2 allows AES-256-GCM
  "if KMS/HSM standardization requires it." XChaCha20-Poly1305 via libsodium is the default here
  because it needs no custom PHP build (verified: `sodium` ships enabled in the target image) and
  its larger nonce space removes the nonce-reuse foot-gun that GCM has at high volume. Revisit if
  the chosen KMS (OpenBao) only speaks AES-GCM natively for envelope operations.

## References

- `BLUEPRINT.md` §4.1–§4.6 (threat model, envelope encryption, encrypted fields, blind indexes,
  object encryption), §25 (crypto-shredding on deletion).
- `docs/AGENTS.md` §8 (security rules: AAD binding, blind index formula, cross-tenant test
  requirement).
- `docs/reference/verified-versions.md` — `php:8.5-fpm-trixie` ships `sodium` and
  `--with-password-argon2`; `openbao/openbao:2.6.2` as the KMS/secrets backend.
