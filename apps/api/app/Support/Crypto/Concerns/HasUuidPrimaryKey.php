<?php

declare(strict_types=1);

namespace App\Support\Crypto\Concerns;

use Illuminate\Support\Str;

/**
 * Assigns a UUIDv7 primary key **in the constructor**, not via a `creating` model event.
 *
 * This matters specifically because of `Casts\Encrypted`: Eloquent casts run at *attribute
 * assignment* time (inside `fill()`/`setAttribute()`), which for a `new Model([...])` call
 * happens synchronously inside the constructor — long before any `creating` event would fire
 * (that only fires on `save()`). The AAD an encrypted field is bound to
 * (`subject_id | table | field | record_uuid`) requires the record's own primary key to already
 * exist at the moment the field is encrypted, or every encrypted attribute set during
 * construction would bind to a null/placeholder record id and never match the id the row is
 * actually saved under. Every model using `Casts\Encrypted` MUST use this trait (or otherwise
 * guarantee its primary key is set before any attribute assignment) — see
 * docs/handoff/005-crypto-core.md "The UUIDv7-in-constructor trap".
 *
 * Sets `$incrementing = false` and `$keyType = 'string'` via Eloquent's `initialize{Trait}` hook
 * (`HasAttributes`/`Model::initializeTraits()` calls this automatically), matching BLUEPRINT.md
 * §5/§6: all public IDs are UUIDv7 or ULID, never an incrementing integer.
 */
trait HasUuidPrimaryKey
{
    public function __construct(array $attributes = [])
    {
        parent::__construct();

        $this->setAttribute($this->getKeyName(), (string) Str::uuid7());

        $this->fill($attributes);
    }

    public function initializeHasUuidPrimaryKey(): void
    {
        $this->incrementing = false;
        $this->keyType = 'string';
    }
}
