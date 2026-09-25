<?php

declare(strict_types=1);

namespace Kern\Platform\Crypto\Contracts;

/**
 * Implemented by any Eloquent model that uses `Casts\Encrypted` on one or more attributes.
 * `Casts\Encrypted` calls this to learn which `TaxSubject` a row's encryption key belongs to —
 * it never assumes the model's own primary key is the subject id (a model like a future
 * `PfProfile` belongs to a subject but is keyed by its own row id).
 */
interface EncryptsWithSubjectDek
{
    /**
     * @return string the owning `TaxSubject` UUIDv7/ULID whose DEK protects this row's
     *                 encrypted attributes.
     */
    public function encryptionSubjectId(): string;
}
