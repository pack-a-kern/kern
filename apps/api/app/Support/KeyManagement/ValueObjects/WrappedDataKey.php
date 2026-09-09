<?php

declare(strict_types=1);

namespace App\Support\KeyManagement\ValueObjects;

/**
 * A Data Encryption Key (DEK) after it has been wrapped ("encrypted") by a Key Management
 * Service. `$ciphertext` is opaque KMS-native output — never the plaintext DEK.
 *
 * BLUEPRINT.md §6.2 stores this as `tax_subjects.encrypted_dek` + `tax_subjects.dek_kek_version`.
 * The KEK version is carried alongside the ciphertext here (not re-derived from it by callers)
 * so `SubjectDekRepository` implementations can persist both columns without knowing anything
 * about the wire format a given KeyManagementService driver uses.
 */
final readonly class WrappedDataKey
{
    public function __construct(
        public string $ciphertext,
        public int $kekVersion,
    ) {
    }
}
