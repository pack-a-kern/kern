# kern/platform

The security spine an application is built on. Nothing here knows what the application is for.

Four things, each of which is unpleasant to retrofit and therefore belongs underneath everything
else: envelope encryption under a per-tenant key, blind indexes so an encrypted column can still be
looked up exactly, a streaming encrypted object store, and a tenant scope that makes "every query is
scoped" the default meaning of `Model::query()` rather than something each author remembers.

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

## What the application must bind

| Contract | Because |
| --- | --- |
| `Tenancy\Contracts\TenantGate` | Only the application knows what permission means. |
| `KeyManagement\Contracts\SubjectDekRepository` | Only the application knows where a tenant's wrapped key is kept. |

Everything else — the KMS driver from `config/crypto.php`, the blind-index hasher, the object store,
the tenant context as a scoped instance — is registered by `PlatformServiceProvider`, and
`encryption_key_records` travels with the package.

## Installing

```json
"require": { "kern/platform": "^1.0" }
```

Then name your tenant's column in `config/tenancy.php` and point `config/crypto.php` at a KMS.
Publishing the shipped defaults is optional: `php artisan vendor:publish --tag=kern-platform-config`.

## Known wart

`ObjectStore\ObjectKey` spells out `subjects/{id}/documents/{id}/{kind}.enc`, and its last segment
comes from `DocumentObjectKind` — an enum of thumbnails and OCR output. That is document vocabulary in
the base, and it is here on purpose rather than by accident: the whole store is typed on `ObjectKey`,
and the closed enum is what makes "an object key cannot contain a name or an identifier" true by
construction instead of by convention. Moving the key to `kern/documents` would take the store with it,
and then nothing else could encrypt a file at rest.

Generalising it means a key built from a tenant, a list of identifiers and a name the *caller*
guarantees is closed — which is a weaker guarantee than the one it has now. Worth doing when a second
kind of stored object exists; not worth weakening the property before then.

## Running its own suite

```
composer install
vendor/bin/pest
```

Laravel with this package, the audit chain, and nothing else. `TenantIsNotATaxpayerTest` is the one to
read: it runs the whole tenancy mechanism with the tenant called a workspace and the column
`workspace_id`, configured and nothing more.
