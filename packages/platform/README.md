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

Laravel with this package, the audit chain, and nothing else. `TenantIsNotATaxpayerTest` is the one to
read: it runs the whole tenancy mechanism with the tenant called a workspace and the column
`workspace_id`, configured and nothing more.
