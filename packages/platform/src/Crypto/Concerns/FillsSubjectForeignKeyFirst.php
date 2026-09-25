<?php

declare(strict_types=1);

namespace Kern\Platform\Crypto\Concerns;

use Kern\Platform\Tenancy\Tenancy;

/**
 * A second, related trap to the one `HasUuidPrimaryKey` solves: `Casts\Encrypted::set()` calls
 * `Contracts\EncryptsWithSubjectDek::encryptionSubjectId()`, which typically reads a foreign-key
 * attribute — the tenant column. Eloquent's `fill()` assigns attributes in the order the caller's
 * array happens to list them, so `new Model(['cnp' => $cnp, <tenant> => $id])` would try to encrypt
 * `cnp` while the tenant is still unset, silently binding the AAD to an empty id.
 *
 * A model using `Casts\Encrypted` and reading its subject id from a plain attribute (not a
 * constructor-injected value — see `HasUuidPrimaryKey`'s docblock for why a required
 * non-array constructor parameter breaks Eloquent's own hydration path) should use this trait to
 * make that attribute's assignment order-independent: it is always set before the rest of
 * `fill()` runs, regardless of where it appears in the caller's array.
 *
 * The attribute is the tenant column by default; override `subjectForeignKeyName()` where a model
 * keys its encryption off something else, as `UserMfaMethod` does with `user_id`.
 */
trait FillsSubjectForeignKeyFirst
{
    public function fill(array $attributes): static
    {
        $key = $this->subjectForeignKeyName();

        if (array_key_exists($key, $attributes)) {
            $this->setAttribute($key, $attributes[$key]);
            unset($attributes[$key]);
        }

        return parent::fill($attributes);
    }

    protected function subjectForeignKeyName(): string
    {
        return Tenancy::column();
    }
}
