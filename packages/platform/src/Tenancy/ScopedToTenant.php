<?php

declare(strict_types=1);

namespace Kern\Platform\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Tenancy\Exceptions\NoTenantContextException;

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
            $column = Tenancy::column();

            if ($model->getAttribute($column) === null) {
                $model->setAttribute($column, app(TenantContext::class)->currentTenantId());
            }
        });
    }

    /**
     * The tenant column is fillable without any model listing it.
     *
     * A `$fillable` array cannot name a column whose name comes from configuration, and a model that
     * left it out would silently drop the one attribute every write needs.
     */
    public function initializeScopedToTenant(): void
    {
        $this->mergeFillable([Tenancy::column()]);
    }

    /**
     * Which tenant this row belongs to.
     *
     * Reading `$model->tax_subject_id` works and is exactly what a package must not do: the
     * attribute's name is this installation's word. Throws rather than returning null — a row of a
     * scoped table with no tenant is not a state any caller can do anything sensible with.
     */
    public function tenantId(): string
    {
        $value = $this->getAttribute(Tenancy::column());

        if (! is_string($value) || $value === '') {
            throw NoTenantContextException::notOnRow(static::class, Tenancy::column());
        }

        return $value;
    }
}
