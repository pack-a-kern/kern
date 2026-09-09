<?php

declare(strict_types=1);

namespace App\Support\ObjectStore;

use App\Support\ObjectStore\Exceptions\InvalidObjectKeyException;
use Illuminate\Support\Str;

/**
 * `subjects/{subject_id}/documents/{document_id}/{kind}.enc` — BLUEPRINT.md §4.6.
 *
 * Constructed only from a subject id, a document id, and a fixed {@see DocumentObjectKind} —
 * never from a free-form string such as an original filename, a CNP, or an IBAN. This is what
 * guarantees "an object key must not contain PII" as a property of the type, not a convention
 * every caller has to remember: the only two identifiers involved must be a UUID or ULID
 * (docs/AGENTS.md §6 — every public id already is one), and the last segment can only ever be one
 * of a closed enum.
 */
final readonly class ObjectKey
{
    private function __construct(
        public string $subjectId,
        public string $documentId,
        public DocumentObjectKind $kind,
        public string $value,
    ) {
    }

    public static function forDocument(string $subjectId, string $documentId, DocumentObjectKind $kind): self
    {
        self::assertIdentifier($subjectId, 'subject id');
        self::assertIdentifier($documentId, 'document id');

        return new self(
            $subjectId,
            $documentId,
            $kind,
            sprintf('subjects/%s/documents/%s/%s', $subjectId, $documentId, $kind->filename()),
        );
    }

    /**
     * The companion object holding this key's encrypted integrity manifest
     * (see docs/handoff/012-encrypted-object-store.md for the manifest format).
     */
    public function manifestKey(): string
    {
        return $this->value . '.manifest';
    }

    private static function assertIdentifier(string $id, string $label): void
    {
        if (! Str::isUuid($id) && ! Str::isUlid($id)) {
            throw InvalidObjectKeyException::notAnIdentifier($label);
        }
    }
}
