<?php

declare(strict_types=1);

namespace Kern\Documents\ObjectStore;

use Illuminate\Support\Str;
use Kern\Documents\ObjectStore\Exceptions\InvalidObjectKeyException;

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
     * `subjects/{subject}/documents/{document}/parts/{part}/{kind}.enc` — one file of a document.
     *
     * A separate segment rather than a suffix on the filename, so a part's objects (original now,
     * a rendered preview later) sit together under one prefix and a deleted part is one prefix to
     * remove.
     */
    public static function forDocumentPart(
        string $subjectId,
        string $documentId,
        string $partId,
        DocumentObjectKind $kind,
    ): self {
        self::assertIdentifier($subjectId, 'subject id');
        self::assertIdentifier($documentId, 'document id');
        self::assertIdentifier($partId, 'part id');

        return new self(
            $subjectId,
            $documentId,
            $kind,
            sprintf('subjects/%s/documents/%s/parts/%s/%s', $subjectId, $documentId, $partId, $kind->filename()),
        );
    }

    /**
     * The key a row already holds, checked rather than trusted.
     *
     * Needed because two layouts coexist: every part created before `document_parts` existed keeps
     * the pre-parts key (`…/documents/{id}/original.enc`), and moving encrypted objects in a
     * migration is the one step that could not be undone if it failed halfway. So the path is
     * stored, and read back through here.
     *
     * This is the *only* way a string becomes an `ObjectKey`, and it stays as strict as the
     * constructors above: the shape must match exactly, both ids must be identifiers, and the last
     * segment must be a known {@see DocumentObjectKind}. A path that fails any of those is refused
     * rather than passed to the object store — a stored value is not automatically a safe one.
     */
    public static function fromStoredPath(string $path): self
    {
        $matched = preg_match(
            '#^subjects/(?<subject>[^/]+)/documents/(?<document>[^/]+)/(?:parts/(?<part>[^/]+)/)?(?<file>[^/]+)$#',
            $path,
            $matches,
        );

        if ($matched !== 1) {
            throw InvalidObjectKeyException::notAnIdentifier('object path');
        }

        $kind = self::kindFromFilename($matches['file']);

        if ($kind === null) {
            throw InvalidObjectKeyException::notAnIdentifier('object kind');
        }

        // Not a trailing group, so PCRE always sets it — empty for a pre-parts path.
        $part = $matches['part'];

        return $part === ''
            ? self::forDocument($matches['subject'], $matches['document'], $kind)
            : self::forDocumentPart($matches['subject'], $matches['document'], $part, $kind);
    }

    private static function kindFromFilename(string $filename): ?DocumentObjectKind
    {
        foreach (DocumentObjectKind::cases() as $kind) {
            if ($kind->filename() === $filename) {
                return $kind;
            }
        }

        return null;
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
