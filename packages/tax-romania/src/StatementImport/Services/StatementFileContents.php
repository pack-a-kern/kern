<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Services;

use Kern\Documents\Models\Document;
use Kern\Documents\Models\DocumentDerivative;
use Kern\Documents\ObjectStore\DocumentObjectKind;
use Kern\Documents\ObjectStore\EncryptedObjectStore;
use Kern\Documents\ObjectStore\Exceptions\ObjectNotFoundException;
use Kern\Documents\ObjectStore\ObjectKey;
use RuntimeException;

/**
 * The bytes a statement's reader is given: the CSV it was converted to, if it was, else the upload.
 *
 * One place for this because the file is read twice — once to propose the columns, once when they
 * are confirmed — and both readings must see the same table. A converted CSV is stored as the
 * document's derivative, so the second reading, and every "ask again", reads what the first did
 * rather than converting again and perhaps getting a table the columns were not chosen on. Deleting
 * or merging the document takes the derivative with it, as it does every other derivative.
 */
final readonly class StatementFileContents
{
    public function __construct(private EncryptedObjectStore $store)
    {
    }

    /** What the reader should read, or null when the file is gone. */
    public function readable(Document $document): ?string
    {
        return $this->converted($document) ?? $this->original($document);
    }

    /** The upload itself, decrypted, or null when it is gone. */
    public function original(Document $document): ?string
    {
        $part = $document->firstPart();

        return $part === null ? null : $this->read($part->objectKey());
    }

    /** The CSV this document was converted to, or null when it never was. */
    public function converted(Document $document): ?string
    {
        $stored = DocumentDerivative::query()
            ->where('document_id', $document->id)
            ->where('kind', DocumentObjectKind::CONVERTED_CSV->value)
            ->exists();

        return $stored ? $this->read($this->convertedKey($document)) : null;
    }

    public function storeConverted(Document $document, string $csv): void
    {
        $key = $this->convertedKey($document);
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw new RuntimeException('A temporary stream could not be opened.');
        }

        try {
            fwrite($stream, $csv);
            rewind($stream);
            $this->store->put($key, $stream);
        } finally {
            fclose($stream);
        }

        DocumentDerivative::query()->updateOrCreate(
            ['document_id' => $document->id, 'kind' => DocumentObjectKind::CONVERTED_CSV],
            [
                'object_path' => $key->value,
                'sha256' => hash('sha256', $csv),
                // The object store's one envelope format so far, as for every part written.
                'encryption_version' => 1,
            ],
        );
    }

    private function convertedKey(Document $document): ObjectKey
    {
        // The document-level key, which is where `DeleteDocument` and `MergeDocuments` look for a
        // derivative's bytes.
        return ObjectKey::forDocument($document->tax_subject_id, $document->id, DocumentObjectKind::CONVERTED_CSV);
    }

    private function read(ObjectKey $key): ?string
    {
        try {
            $stream = $this->store->get($key);
        } catch (ObjectNotFoundException) {
            return null;
        }

        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents === false ? null : $contents;
    }
}
