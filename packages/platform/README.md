# kern/platform

The security spine an application is built on. Nothing here knows what the application is for.

Four things, each of which is unpleasant to retrofit and therefore belongs underneath everything
else: envelope encryption under a per-tenant key, blind indexes so an encrypted column can still be
looked up exactly, a tenant scope that makes "every query is scoped" the default meaning of
`Model::query()` rather than something each author remembers, and the rule that only a person may
approve a version of anything.

## Encrypting a column

```php
use Kern\Platform\Crypto\Casts\Encrypted;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

final class Invoice extends Model
{
    use HasUuidPrimaryKey;

    protected function casts(): array
    {
        return ['recipient_name' => Encrypted::class];
    }
}
```

The ciphertext carries its algorithm, key version, nonce and tag, and is bound to
`tenant|table|column|row id` as associated data — so a value copied into another row fails to
decrypt rather than quietly decrypting as somebody else's. That binding is why the primary key must
exist before the attribute is set, which is what `HasUuidPrimaryKey` is for: it assigns the id in the
constructor, not on `creating`.

A model that must be findable by an encrypted value also stores a blind index of it — a keyed hash
of the normalised value, under a *global* key rather than the tenant's, because logging in by email
and finding a duplicate identifier are both cross-tenant questions. The tradeoff is stated plainly:
a holder of that key can test two tenants' rows for equality.

## Scoping to a tenant

```php
use Kern\Platform\Tenancy\ScopedToTenant;

final class Invoice extends Model
{
    use ScopedToTenant;
}
```

Reads are restricted to the tenant now bound and inserts are stamped with it. Reaching past that is
`Invoice::withoutGlobalScope(TenantScope::class)` — explicit, greppable, and the only way. With no
tenant bound at all it throws, rather than returning everything or nothing: one hands a person's data
to somebody else, the other shows them an empty page they cannot explain.

Binding goes through `TenantContext::authorizeAndActivate()`, which asks the application every time:

```php
final class SeatGate implements Kern\Platform\Tenancy\Contracts\TenantGate
{
    public function roleFor(string $userId, string $tenantId): ?string { /* … */ }
}
```

There is deliberately no "just set the id" method. What a tenant is called is
`config('tenancy.column')`; who may act for one is the gate. The package supplies neither.

## What must never reach a log file

A Monolog processor strips passwords, tokens, `Authorization` headers, JWTs, IBANs and OTP codes from
every message and every context value, however deeply nested, before a line is written. It carries the
shapes that mean the same thing in any application and asks `Logging\Redactions` for the rest:

```php
$redactions->keys('cnp', 'cui', 'cod_fiscal');       // values dropped by key name
$redactions->pattern('/\b[1-8]\d{12}\b/');           // and by shape, anywhere in free text
```

Declared from a package's own provider, because a CNP is thirteen digits starting 1-8 and only the
package that knows what a taxpayer is can say so. A registry rather than configuration for the reason
two packages always find: a package's config file is merged one level deep, so the second one to
register would replace the first's list instead of adding to it.

The risk that shape carries is worth stating: a package that forgets to declare its identifiers leaks
them silently. The answer is that the test belongs beside the words — `kern/tax-romania`'s own suite
fails if its declaration is removed.

## Sending a playbook to the browser worker

`BrowserWorker\BrowserWorkerClient` is the one way to `apps/browser-worker`; a package brings its playbook
under `config('playbooks.<key>')` and nothing else. On the way out every playbook is dressed by
`PlaybookContext` from the Browser card: its engine and `User-Agent` together (`SOURCES_BROWSER_ENGINE`,
`SOURCES_BROWSER_USER_AGENT`), and the proxy (`BROWSER_WORKER_PROXY_*`) when it is on and the playbook's
`name` is ticked for it. A playbook that names its own engine, name, device or proxy keeps it. Both are
saved on the card in their own dialogs, behind step-up and audited — the proxy's password enciphered and
never shown.

## What the application must bind

| Contract | Because |
| --- | --- |
| `Tenancy\Contracts\TenantGate` | Only the application knows what permission means. |
| `KeyManagement\Contracts\SubjectDekRepository` | Only the application knows where a tenant's wrapped key is kept. |

Everything else — the KMS driver from `config/crypto.php`, the blind-index hasher, the tenant context
as a scoped instance — is registered by `PlatformServiceProvider`, and `encryption_key_records`
travels with the package.

## Installing

```json
"require": { "kern/platform": "^1.0" }
```

Then name your tenant's column in `config/tenancy.php` and point `config/crypto.php` at a KMS.
Publishing the shipped defaults is optional: `php artisan vendor:publish --tag=kern-platform-config`.

## What it deliberately does not have

**A file store.** It has the cipher — `EnvelopeCipher`, and the associated data that binds a
ciphertext to the row it belongs to — but streaming a *file* through that cipher is
`kern/documents`, along with the key type that says a stored object can only ever be a document's.
It lived here until somebody asked what the base actually encrypted with it, and the answer was
nothing.

## Running its own suite

```
composer install
vendor/bin/pest
```

`tests/Unit` is this package's classes as plain PHP — no container, no database, milliseconds.
`tests/Integration` boots Laravel with this package and its dependencies and nothing else, which is
the only way to find out whether it is actually installable.

## Rotating a key

Two keys are rotatable, and each is only half rotated by the act of rotating it. Both runbooks below
end in a command that has to report zero, because until it does, the old key is still in use.

`docker compose exec app` is how these are reached in the bundled stack — drop the prefix if you run
artisan directly. Leave out `-T`: `rotate-kek` asks for confirmation.

### The KEK — the master key, in OpenBao

Nothing to edit. The key never leaves the KMS.

```
docker compose exec app php artisan kern:keys:rewrap-deks --dry-run
docker compose exec app php artisan kern:keys:rotate-kek
docker compose exec app php artisan kern:keys:rewrap-deks
```

1. **Dry run first.** Prints every stored data key by table and KEK version. Nothing should be behind
   the current version before you start; if something is, a previous rotation was not finished, and
   running `rewrap-deks` on its own is all that is needed.
2. **`rotate-kek`** mints the next version, writes `encryption_key_records` (the new version `ACTIVE`,
   the one it replaced `RETIRED`), and records a `KekRotated` audit event. Instantaneous and
   non-disruptive: the KMS keeps prior versions, so every already-wrapped key still decrypts. Add
   `--force` to skip the prompt.
3. **`rewrap-deks`** is the half that makes it mean something. Rotating alone leaves every existing
   data key wrapped under the *old* version, so the version you just retired is still what protects
   them. This unwraps each one under the old version and wraps the same bytes under the new, across
   `tax_subjects`, `users` and `system_encryption_keys`. **No encrypted field is re-encrypted** — a
   data key's own bytes do not change, only the wrapper around them. `--dry-run` reports without
   writing.

Interrupting it is safe. Some rows end up on the old version and some on the new; both decrypt,
because the KMS still holds both. Run it again and it finishes — it skips what is already current, so
it is safe to run at any time, rotation or not.

**Then check:** sign in, open the AI settings screen (that value is sealed under the system-wide data
key, so it exercises the third table), load a wallet page, and run `rewrap-deks --dry-run` once more —
it should report nothing behind.

Only now may an old KEK version be deleted in the KMS, and `rewrap-deks` reporting zero is the
evidence for it: it is what proves no `encrypted_dek` still depends on that version. Deleting one
(`min_decryption_version` on the key's config) takes the root token: the application's own token
cannot, by design — see ADR 0002.

### A taxpayer's data key — not rotatable yet

Each taxpayer's DEK is created once and never replaced; rotating the KEK only re-wraps it. There is
no command that makes a leaked DEK useless (ADR 0002, "A subject's DEK is never rotated").

If you have reason to think a plaintext DEK left the server — a memory dump, a core file, a debug log
that should not exist — until DEK rotation is built:

1. Close the way it left. A plaintext DEK exists only in a running process's memory.
2. Rotate the KEK and rewrap (above). It does not help the leaked key, but if the leak reached the
   KMS side too, it protects every other taxpayer.
3. Treat that taxpayer's encrypted data — CNP, names, documents — as readable by whoever holds the
   key, and record it as an incident. There is no remedy in the application today: crypto-shredding
   a taxpayer (destroying its wrapped DEK, `BLUEPRINT.md` §25) is the design but has no command yet,
   and it would not be a fix either — it destroys the data for its owner as well.

### The blind-index key — the HMAC key, in the KMS

It sits beside the KEK, under its own name (`OPENBAO_BLIND_INDEX_KEY_NAME`, default `blind-index`),
and never leaves it: the application asks the KMS for each index, which is why a copy of the database
and of the environment file together reveal nothing (`docs/adr/0020-blind-index-key-in-the-kms.md`).
The application's token can compute with it and read its version — it cannot rotate it.

This one has a window. A blind index is `HMAC-SHA-256(key, value)`, so the moment the current version
changes, every stored index is a hash under a key nothing searches with any more: **sign-in by email,
duplicate-CNP detection, counterparty matching and tag lookup by name all stop matching** until the
reindex finishes. Existing sessions are unaffected. Plan it accordingly — on a small installation it
is seconds, and the reindex is the whole of it.

With the root token, inside the `openbao` container:

```
bao write -f transit/keys/blind-index/rotate
```

then, at once:

```
docker compose exec app php artisan kern:keys:reindex-blind-indexes
```

No recreate: nothing in the environment changed. The previous version stays in the KMS, which is what
lets a row that could not be recomputed still be matched by nothing worse than a missing index.

`reindex-blind-indexes` walks every blind-indexed column, recomputes each index from the ciphertext
beside it, and prints examined / rewritten / could not recompute per column. **The last column must be
zero.** It exits non-zero otherwise, and if it does, stop here — every index it could not recompute is
a row that is only findable under the old key version.

Run it a second time: it should report nothing rewritten and nothing stuck. **Then check:** sign in
with an email address — that is the blind index doing its job.

**Never trim old versions of this key** (`min_decryption_version`, `min_available_version`) while any
index may still depend on them.

### Moving an installation off the environment-file key

An installation from before the key moved has its indexes under the key `CRYPTO_BLIND_INDEX_KEYS` used
to hold. Deploy, then run `kern:keys:reindex-blind-indexes` straight away — it recomputes every index
from its encrypted value under the KMS key, and sign-in by email does not match until it has. Then
delete `CRYPTO_BLIND_INDEX_KEYS` and `CRYPTO_BLIND_INDEX_CURRENT_VERSION` from the environment file;
nothing reads them.

### Why both commands exist at all

Rotating a key is one call. Retiring the old one is the whole job, and the two are not the same
operation — which is why each runbook is two steps and each ends in a count. Every blind-indexed
column in this package's schema keeps the ciphertext of the value it indexes for the same reason: a
hash cannot be reversed, so a column holding only the index could never be re-keyed. See
`docs/adr/0002-envelope-encryption.md` and `docs/handoff/005-crypto-core.md`.
