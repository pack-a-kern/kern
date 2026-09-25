# kern/audit

An append-only, hash-chained audit log.

Every entry carries the hash of the entry before it in its chain, so a row that is edited or removed
shows up as a break: the chain no longer verifies from that point on. The database refuses `UPDATE`
and `DELETE` on the table outright — the model's overrides are a second, cheaper line of defence
that turns an accidental write into an exception instead of a round trip.

## What it gives you

```php
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;

$audit->record(AuditEventInput::occurringNow(
    subjectId: $tenantId,          // null for something that belongs to no tenant
    actorId: $userId,              // null for the system acting on its own
    eventType: 'DocumentDeleted',
    resourceType: 'Document',
    resourceId: $documentId,
    payloadHash: $audit->hashPayload(['sha256' => $sha256]),
));
```

`ChainVerifier` reads a chain back and says where, if anywhere, it stops verifying.

## Chains are per subject

One global chain would serialise every write in the installation behind a single row lock. A chain
per subject keeps writes parallel and keeps the guarantee where it matters: a tenant's own history
is complete and ordered.

## What it never stores

The payload. Only a hash of it. An audit trail that quietly recorded what it was auditing would
undo the encryption of the thing it was auditing — so the chain records *that* something happened,
to what, by whom, and in what order, and the thing itself stays where it was.

## Installing

```json
"require": { "kern/audit": "^1.0" }
```

The migrations travel with the package. Nothing to publish, nothing to remember.

## Running its own suite

```
composer install
vendor/bin/pest
```

It boots Laravel with this package and nothing else — no users, no tenants — because that is the only
way to find out whether it is installable. The first run found that it was not.
