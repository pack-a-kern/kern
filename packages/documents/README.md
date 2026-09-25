# kern/documents

A document a tenant owns, from the moment it arrives to the moment somebody reads a figure off it.

Upload in parts, quarantine, virus scan, encryption at rest under the tenant's own key, a text layer
where the file has one, and — only with consent that was recorded, never assumed — an external
reading of what the page says.

## The path a file takes

```
POST documents          → quarantined, encrypted, one part per file
  → ScanQuarantinedPart → clamd; infected parts are rejected and the bytes deleted
  → ProcessScannedDocument
       text layer?      → extracted locally, nothing leaves the machine
       no text layer?   → consent checked, then sent to the configured provider
  → READY, with a DocumentExtraction a person reviews, corrects or accepts
```

Nothing is sent anywhere by default: with no provider configured, the null provider throws, the job
catches it, and the document stays readable exactly as it was.

## What it never decides

**What a tenant is called.** `ResolvePaymentParties` answers "is this printed party the tenant, and
therefore which way did the money go?" — the question a model answered wrongly often enough to be
taken away from it. It needs the tenant's names and gets them from
`Kern\Platform\Tenancy\Contracts\TenantNames`; where those names are kept and how they are decrypted
is the application's business. The comparison is local and only its conclusion travels.

**What the tenant column is called.** Every query, every `create()` and every migration here asks
`Kern\Platform\Tenancy\Tenancy` for the word. On this installation it is `tax_subject_id`; a package
that had written that down could only ever serve taxpayers.

**Where its routes hang from.** `config('kern.routing.tenant')` says, and the application says that.

## What the application keeps

Turning a reviewed document into a ledger transaction. That is a document meeting a wallet, and a
package cannot reach into another — so `POST documents/{document}/transaction` is registered by the
application, with everything it needs already public here.

## Installing

```json
"require": { "kern/documents": "^1.0" }
```

Migrations, routes, config and the rate limiter arrive with it. Bind `TenantNames`, point
`documents.quarantine_disk` and `objectstore.disk` at real disks, and give it a clamd to talk to.

## Running its own suite

```
composer install
vendor/bin/pest
```

Laravel with this package, the platform and the audit chain — and a *workspace* for a tenant, so the
suite fails if anything here starts assuming a taxpayer again.
