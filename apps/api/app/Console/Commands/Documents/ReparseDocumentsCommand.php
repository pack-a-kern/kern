<?php

declare(strict_types=1);

namespace App\Console\Commands\Documents;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentDerivative;
use App\Domain\Documents\Models\DocumentExtraction;
use App\Domain\Documents\Parsing\ProposeParsedExtraction;
use App\Domain\Subjects\Tenancy\TaxSubjectScope;
use App\Support\ObjectStore\DocumentObjectKind;
use App\Support\ObjectStore\EncryptedObjectStore;
use App\Support\ObjectStore\ObjectKey;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-reads already-extracted documents with the current parsers.
 *
 * A document processed before a parser existed — or before the parser that would now recognise it
 * was written — has its text sitting in the object store and no proposal to show for it. Nothing
 * needs re-uploading or re-scanning: the `extracted_text` derivative is exactly what a parser reads.
 *
 * Skips any document that already has an extraction. Re-reading one would either duplicate a
 * proposal a person has already dealt with, or worse, propose again over a correction they made.
 * `--force` exists for the case where a parser was fixed and the old readings are known to be wrong;
 * it still leaves reviewed extractions alone.
 *
 * Resolves documents `withoutGlobalScope(TaxSubjectScope::class)`: this is the system's own
 * maintenance pass over every subject, with no authenticated user to bind a tenant context to — the
 * same category `ScopedToTaxSubject` names for admin tooling.
 */
final class ReparseDocumentsCommand extends Command
{
    /** @var string */
    protected $signature = 'taxos:documents:reparse
        {--force : Re-read documents that already have an unreviewed proposal}
        {--limit=500 : Stop after this many documents}';

    /** @var string */
    protected $description = 'Re-read stored document text with the current parsers, proposing extractions for documents that have none.';

    public function handle(EncryptedObjectStore $store, ProposeParsedExtraction $propose): int
    {
        $force = $this->option('force') === true;
        $limit = max(1, (int) $this->option('limit'));

        /** @var list<Document> $documents */
        $documents = Document::query()
            ->withoutGlobalScope(TaxSubjectScope::class)
            ->where('status', DocumentStatus::READY->value)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all();

        $proposed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($documents as $document) {
            $existing = DocumentExtraction::query()->where('document_id', $document->id)->get();

            if ($existing->isNotEmpty()) {
                // Never touch a reviewed extraction: a person's decision is not something a
                // maintenance command gets to redo.
                $reviewed = $existing->contains(fn (DocumentExtraction $row): bool => $row->status->value !== 'PROPOSED');

                if (! $force || $reviewed) {
                    $skipped++;

                    continue;
                }

                DocumentExtraction::query()
                    ->where('document_id', $document->id)
                    ->where('status', 'PROPOSED')
                    ->delete();
            }

            $text = $this->readExtractedText($document, $store);

            if ($text === null) {
                $skipped++;

                continue;
            }

            try {
                $proposed += $propose($document, $text) === null ? 0 : 1;
            } catch (Throwable $exception) {
                // One unreadable document must not stop the pass over all the others.
                report($exception);
                $failed++;
            }
        }

        $this->components->info(sprintf(
            '%d document(s) examined: %d proposal(s) created, %d skipped, %d failed.',
            count($documents),
            $proposed,
            $skipped,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The stored extracted text, or null when this document has none.
     *
     * Images have no text derivative — only PDFs go through PyMuPDF — so a null here is the
     * ordinary case rather than a fault.
     */
    private function readExtractedText(Document $document, EncryptedObjectStore $store): ?string
    {
        $hasDerivative = DocumentDerivative::query()
            ->where('document_id', $document->id)
            ->where('kind', DocumentObjectKind::EXTRACTED_TEXT->value)
            ->exists();

        if (! $hasDerivative) {
            return null;
        }

        $key = ObjectKey::forDocument($document->tax_subject_id, $document->id, DocumentObjectKind::EXTRACTED_TEXT);

        try {
            $stream = $store->get($key);
        } catch (Throwable) {
            return null;
        }

        try {
            $text = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        return $text === false ? null : $text;
    }
}
