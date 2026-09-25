<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Kern\TaxRomania\StatementImport\Enums\ImportNoticeKind;
use Kern\TaxRomania\StatementImport\Models\StatementImport;

/**
 * One notice about one import, kept for the bell and pushed to the user's own channel.
 *
 * Ids, counts and closed codes only. The table it is stored in is not encrypted, and the channel is
 * a websocket — neither is a place for a counterparty or an amount, and the bell needs neither: it
 * says what happened and links to the import, which is read the usual way.
 */
final class StatementImportNotice extends Notification
{
    public function __construct(
        private readonly StatementImport $import,
        private readonly ImportNoticeKind $kind,
        private readonly int $rowsSuggested = 0,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'statement-import';
    }

    public function broadcastType(): string
    {
        return 'statement-import';
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind->value,
            'subject_id' => $this->import->tax_subject_id,
            'import_id' => $this->import->id,
            'status' => $this->import->status->value,
            'row_count' => $this->import->row_count,
            'imported_count' => $this->import->imported_count,
            'rows_suggested' => $this->rowsSuggested,
            'failure_reason' => $this->import->failure_reason,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
