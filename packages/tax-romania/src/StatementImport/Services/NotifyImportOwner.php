<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Services;

use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\StatementImport\Enums\ImportNoticeKind;
use Kern\TaxRomania\StatementImport\Enums\RowProposalSource;
use Kern\TaxRomania\StatementImport\Models\StatementImport;
use Kern\TaxRomania\StatementImport\Models\StatementImportRow;
use Kern\TaxRomania\StatementImport\Notifications\StatementImportNotice;

/**
 * Tells the person who started an import that it has moved on without them.
 *
 * Only them: they uploaded the file, and they are the one waiting. An import started before imports
 * recorded who started them has no one to tell, and is left alone rather than guessed at.
 */
final readonly class NotifyImportOwner
{
    public function __invoke(StatementImport $import, ImportNoticeKind $kind): void
    {
        if ($import->created_by === null) {
            return;
        }

        $owner = User::query()->find($import->created_by);

        if ($owner === null) {
            return;
        }

        $suggested = $kind === ImportNoticeKind::READY_FOR_REVIEW
            ? StatementImportRow::withoutGlobalScope(TenantScope::class)
                ->where('import_id', $import->id)
                ->where('proposal_source', RowProposalSource::AI)
                ->count()
            : 0;

        $owner->notify(new StatementImportNotice($import, $kind, $suggested));
    }
}
