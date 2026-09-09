<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Tenancy;

use function app;

use Illuminate\Database\Eloquent\Model;

/**
 * The reusable tenant-scoping mechanism every future business module (Wallet, Ledger, Documents,
 * …) should put on any Eloquent model with a `tax_subject_id` column. Two effects, both automatic:
 *
 *  1. **Every read is scoped.** `static::addGlobalScope(new TaxSubjectScope())` makes every
 *     `Model::query()`/`find()`/`all()`/relationship query built through this model implicitly
 *     add `WHERE tax_subject_id = <current tenant>` — a caller has to go out of their way
 *     (`Model::withoutGlobalScope(TaxSubjectScope::class)`, an explicit, greppable, self-
 *     documenting call naming exactly what it bypasses) to see another subject's rows. This is
 *     the "make the unsafe thing hard to write" requirement: the *scoped* query is what
 *     `Model::query()` means by default; an *unscoped* one requires spelling out the class you're
 *     opting out of.
 *  2. **Every insert is auto-tagged.** The `creating` hook fills `tax_subject_id` from the bound
 *     `TenantContext` if the caller did not already set it explicitly — so a plain
 *     `SomeModel::create([...])` inside an authorized request can never accidentally omit the
 *     tenant column, and a caller who legitimately needs to create a row for a *different*
 *     subject (a system job, an admin action) must pass `tax_subject_id` explicitly, which is
 *     visible in the calling code.
 *
 * A model using this trait MUST NOT also need `App\Support\Crypto\Concerns\
 * FillsSubjectForeignKeyFirst` for encryption-AAD ordering *and* rely on this trait's
 * auto-fill-on-create for the same column without checking the interaction: `Casts\Encrypted`'s
 * `set()` (which reads `tax_subject_id` for both the DEK lookup and the AAD) runs during
 * attribute assignment inside `fill()`/`new Model([...])`, which happens *before* the `creating`
 * event this trait's auto-fill hook listens on. A model with both an encrypted field and this
 * trait must still pass `tax_subject_id` explicitly at construction time — this trait's auto-fill
 * only helps for models with no encrypted fields, or ones happy to leave `tax_subject_id` unset
 * until the `creating` event, which is never true for a model using `Casts\Encrypted`.
 *
 * Requires `App\Domain\Subjects\Tenancy\TenantContext` to already have an authorized subject
 * bound (`TenantContext::authorizeAndActivate()`) — see `TaxSubjectScope` for what happens if not.
 */
trait ScopedToTaxSubject
{
    protected static function bootScopedToTaxSubject(): void
    {
        static::addGlobalScope(new TaxSubjectScope());

        static::creating(static function (Model $model): void {
            if ($model->getAttribute('tax_subject_id') === null) {
                $model->setAttribute('tax_subject_id', app(TenantContext::class)->currentSubjectId());
            }
        });
    }
}
