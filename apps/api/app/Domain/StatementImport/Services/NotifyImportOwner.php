<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Services;

use App\Domain\Identity\Models\User;
use App\Domain\StatementImport\Enums\ImportNoticeKind;
use App\Domain\StatementImport\Enums\RowProposalSource;
use App\Domain\StatementImport\Models\StatementImport;
use App\Domain\StatementImport\Models\StatementImportRow;
use App\Domain\StatementImport\Notifications\StatementImportNotice;
use App\Support\Tenancy\TenantScope;

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
