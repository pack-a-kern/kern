# ADR 0020 — The blind-index key lives in the KMS

**Status:** accepted
**Date:** 2026-10-19
**Amends:** [ADR 0002](0002-envelope-encryption.md) — where the second key class is held, not what it
is. It stays one global, versioned HMAC key, distinct from every DEK.

## Context

ADR 0002 defines two key classes: a per-subject DEK wrapped by a KEK in OpenBao, and one global
blind-index key used to compute `HMAC-SHA-256(key, value)` for the columns that must be found by exact
value — email at sign-in, CNP when a taxpayer registers, counterparty and tag names. It says the second
is *separate*. It does not say it must be held somewhere else.

The code did. `ConfigBlindIndexKeyProvider` read the key from `CRYPTO_BLIND_INDEX_KEYS` in
`apps/api/.env`, with a comment calling that "deliberately not the KMS" because the key is "HMAC key
material for equality lookups, not a DEK". That conflates two questions: whether it is a different key
(yes) and whether it should live in a different place (nothing follows from the first answer).

The security audit of 2026-10-19 (`docs/SECURITY-FINDINGS.md`, finding 7) showed what the placement
cost. A CNP has on the order of 10^10 possible values. With a copy of the database and a copy of the
environment file — two things an operator plausibly copies together — every possible CNP can be hashed
offline in minutes, and every taxpayer's CNP read off `fiscal_identifier_blind_index` without OpenBao,
without a DEK, and without decrypting anything. `DEPLOYMENT.md` §5's statement that a stolen database
is useless without the master key was false for exactly the field that matters most.

## Decision

The blind-index key is a transit key inside OpenBao (`type=hmac`, name `OPENBAO_BLIND_INDEX_KEY_NAME`,
default `blind-index`), created by `docker/openbao/entrypoint.sh` beside the KEK. It never leaves the
server. `BlindIndexHasher` asks the KMS for each index through `KeyManagementService::blindIndexes()`,
one request per batch.

- **Stored format unchanged.** Transit returns `vault:v<N>:<base64>`; the column holds the same 64
  lowercase hex characters as before. The version is the KMS's to report
  (`currentBlindIndexKeyVersion()`), not something to compare.
- **The application cannot rotate it.** Its policy grants `hmac` and `read` on this key, not `rotate`.
  Rotating makes every stored index stale until `kern:keys:reindex-blind-indexes` runs, which is an
  operator's decision; a compromised application should not be able to make every account unfindable.
- **No `.env` value, no fallback.** `CRYPTO_BLIND_INDEX_KEYS` and `CRYPTO_BLIND_INDEX_CURRENT_VERSION`
  are gone, and so is the read-only "search index key version" setting that displayed the latter.

## Consequences

- A copy of the database and of `apps/api/.env` together no longer reveals any CNP or email. An
  attacker needs the running KMS and a token, and enumerating 10^10 candidates through it is slow and
  visible rather than silent and offline.
- Every lookup by an encrypted value is a request to OpenBao. Sign-in pays one. A statement import pays
  one per file and the re-index one per batch of 200, because both go through `computeMany()`.
- The KMS being down now stops sign-in and registration, not only decryption. It already stopped
  everything that reads encrypted data, which sign-in also does, so the set of things that work with
  the KMS down is not meaningfully smaller.
- **Upgrading an installation** that had the old key: deploy, then run
  `kern:keys:reindex-blind-indexes` at once. It recomputes every index from its encrypted value under
  the KMS key; sign-in by email does not match until it has. Then delete the two old variables. The
  runbook is in `packages/platform/README.md`.
- `BlindIndexHasher::computeWithVersion()`, which nothing called, was removed with the provider.

## Alternatives considered

- **Keep the key in `.env`, but separate from the other secrets** — in its own file, or a Docker
  secret. Narrows who can read it; does not change that whoever copies the host's files gets the
  database's lookup key with it. Rejected.
- **Compute locally with a key fetched from the KMS at boot.** Keeps lookups fast, but puts the key in
  every PHP process's memory and lets anyone who can read that memory compute offline again. It also
  needs the key to be exportable, which transit keys deliberately are not. Rejected.
- **A per-subject blind-index key.** ADR 0002 already explains why not: "does this CNP exist under
  another subject" would become undecidable without decrypting every subject's data.

## References

- `docs/SECURITY-FINDINGS.md` finding 7
- `packages/platform/src/Crypto/BlindIndex/BlindIndexHasher.php`
- `packages/platform/src/KeyManagement/OpenBaoTransitKeyManagementService.php`
- `docker/openbao/entrypoint.sh`
