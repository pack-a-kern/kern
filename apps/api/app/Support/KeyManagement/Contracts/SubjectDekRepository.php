<?php

declare(strict_types=1);

namespace App\Support\KeyManagement\Contracts;

use App\Support\KeyManagement\ValueObjects\WrappedDataKey;

/**
 * Persistence boundary for a `TaxSubject`'s wrapped DEK. `tax_subjects` does not exist in this
 * module's scope (Identity/Subjects owns it — BLUEPRINT.md §6.1/§6.2: `encrypted_dek` +
 * `dek_kek_version` columns). Whoever implements `TaxSubject` must provide a concrete
 * implementation of this interface — see docs/handoff/005-crypto-core.md "What the identity
 * agent must implement" for the exact column mapping expected.
 *
 * Implementations must never expose or log the wrapped ciphertext outside normal persistence
 * (it is safe to store/log-as-opaque-blob, but treat it as sensitive by convention — it is the
 * only thing standing between an attacker and every encrypted field for that subject).
 */
interface SubjectDekRepository
{
    /**
     * @param string $subjectId the `TaxSubject` UUIDv7/ULID.
     */
    public function find(string $subjectId): ?WrappedDataKey;

    /**
     * Persist the wrapped DEK for a subject that does not have one yet. Implementations must
     * treat this as create-only (idempotent create, e.g. an insert guarded by a unique
     * constraint) — `SubjectDekService` calls this exactly once per subject, the first time a
     * subject has no wrapped DEK on record, never to overwrite an existing one.
     */
    public function save(string $subjectId, WrappedDataKey $wrapped): void;
}
