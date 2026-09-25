<?php

declare(strict_types=1);

namespace Kern\Platform\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Kern\Platform\Tenancy\Exceptions\NoTenantContextException;

/**
 * Every query built through a tenant-scoped model is limited to the tenant now bound.
 *
 * This is what "every business query is scoped" actually is: not a convention a developer has to
 * remember at each `where`, but the default meaning of `Model::query()`. Seeing another tenant's
 * rows takes `Model::withoutGlobalScope(TenantScope::class)` — a phrase that names what it skips
 * and can be found by searching for it.
 *
 * Fails loudly when nothing is bound ({@see NoTenantContextException}) rather than matching zero
 * rows or all of them: one turns a person's own data into an unexplained empty page, the other
 * hands it to somebody else.
 *
 * @implements Scope<Model>
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where(
            $model->qualifyColumn(config()->string('tenancy.column')),
            app(TenantContext::class)->currentTenantId(),
        );
    }
}
