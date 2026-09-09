<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Tenancy;

use function app;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query built through a `App\Domain\Subjects\Tenancy\ScopedToTaxSubject` model
 * to `tax_subject_id = TenantContext::currentSubjectId()` — the mechanism `docs/AGENTS.md` §8's
 * "every business query is scoped to `tax_subject_id`" actually is, for any future module (Wallet,
 * Ledger, Documents, …) that adopts the trait.
 *
 * Resolves `TenantContext` via the `app()` helper, not constructor injection: Eloquent
 * instantiates/stores global scope objects itself (`Model::addGlobalScope(new self())`, called
 * from the trait's boot hook, never through the container), so there is no injection point — the
 * same narrow, forced exception to "no service locators" that `App\Support\Crypto\Casts\Encrypted`
 * documents for the identical reason.
 *
 * Deliberately fails loudly (`NoTenantContextException`) rather than matching zero or all rows
 * when no tenant is bound — see that exception's own docblock for why.
 *
 * @implements Scope<Model>
 */
final class TaxSubjectScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where(
            $model->qualifyColumn('tax_subject_id'),
            app(TenantContext::class)->currentSubjectId(),
        );
    }
}
