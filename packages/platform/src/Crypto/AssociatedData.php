<?php

declare(strict_types=1);

namespace Kern\Platform\Crypto;

/**
 * The Additional Authenticated Data bound into every field ciphertext (BLUEPRINT.md §4.2):
 *
 *     subject_id | table | field | record_uuid
 *
 * AEAD authenticates this string alongside the ciphertext without encrypting it — decryption
 * fails unless the exact same four values are supplied again. This is what makes moving a
 * ciphertext to a different row, column, table, or subject fail to decrypt: any one of the four
 * segments changing produces a different AAD, which `sodium_crypto_aead_*_decrypt` rejects.
 *
 * The separator is `|` with no escaping of `|` inside segments. This is safe here because every
 * segment is a system-generated identifier (a UUID/ULID, a table name, a column name) that this
 * codebase controls — never free-form user input — so a segment can never itself contain `|` in
 * a way that could make two distinct (subject, table, field, record) tuples collide onto the
 * same AAD bytes.
 */
final readonly class AssociatedData
{
    private function __construct(public string $bytes)
    {
    }

    public static function for(
        string $subjectId,
        string $table,
        string $field,
        string $recordUuid,
    ): self {
        return new self(implode('|', [$subjectId, $table, $field, $recordUuid]));
    }
}
