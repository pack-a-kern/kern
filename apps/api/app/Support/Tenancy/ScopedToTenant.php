<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Model;

/**
 * Put this on any model whose table has the tenant column, and two things become automatic.
 *
 *  1. **Every read is scoped.** `Model::query()`, `find()`, `all()` and every relationship built
 *     through the model carry `WHERE <tenant column> = <the bound tenant>`. Seeing past it takes
 *     `Model::withoutGlobalScope(TenantScope::class)` — explicit, greppable, and naming exactly
 *     what it opts out of. The unsafe thing is the one that is hard to write.
 *  2. **Every insert is stamped.** The `creating` hook fills the tenant column from the bound
 *     context when the caller has not set it, so a plain `Model::create([...])` inside an
 *     authorized request cannot forget it. A caller who legitimately writes for another tenant —
 *     a system job, an admin action — must pass the column explicitly, which is visible where it
 *     is written.
 *
 * A model with an encrypted attribute must still pass the tenant column at construction time. The
 * `Encrypted` cast reads it during attribute assignment, to find the tenant's key and to build the
 * associated data, and that happens inside `fill()` — before the `creating` event this trait's
 * auto-fill listens on. The auto-fill helps models with no encrypted fields; the others say it.
 *
 * Requires an authorized tenant to be bound already ({@see TenantContext}); {@see TenantScope} is
 * where it says so if none is.
 */
trait ScopedToTenant
{
    protected static function bootScopedToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(static function (Model $model): void {
            $column = config()->string('tenancy.column');

            if ($model->getAttribute($column) === null) {
                $model->setAttribute($column, app(TenantContext::class)->currentTenantId());
            }
        });
    }
}
