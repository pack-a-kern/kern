<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Actions;

use Kern\TaxRomania\StatementImport\Enums\StatementImportStatus;
use Kern\TaxRomania\StatementImport\Events\StatementImportChanged;
use Kern\TaxRomania\StatementImport\Exceptions\ImportNotOpenException;
use Kern\TaxRomania\StatementImport\Jobs\ProposeRowCategories;
use Kern\TaxRomania\StatementImport\Models\StatementImport;

/**
 * Asks the model again about the rows that still have no answer.
 *
 * The way to recover from a failed round without losing work. Before this, the only way to ask again
 * was to confirm the columns a second time, which rebuilt every row and threw away every category a
 * person had already set. This keeps the rows, and asks only about those with no category that no
 * person has touched — see `ProposeRowCategories` — so an answer given by hand is never replaced.
 */
final readonly class RequestRowSuggestions
{
    public function __invoke(StatementImport $import, ?string $actorId): void
    {
        if ($import->status !== StatementImportStatus::REVIEW) {
            throw ImportNotOpenException::forImport($import->id);
        }

        // Recorded first, so the screen shows suggestions coming rather than the round that failed.
        $import->queueClassification();

        ProposeRowCategories::dispatch($import->id, $actorId);

        StatementImportChanged::dispatch($import->id, $import->tax_subject_id, $import->status, true);
    }
}
