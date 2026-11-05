<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Kern\Platform\Notifications\Channels\WebPushChannel;
use Kern\Platform\Notifications\Contracts\SendsPush;
use Kern\Platform\Notifications\DataTransferObjects\PushMessage;
use Kern\TaxRomania\StatementImport\Enums\ImportNoticeKind;
use Kern\TaxRomania\StatementImport\Models\StatementImport;

/**
 * One notice about one import, kept for the bell and pushed to the user's own channel.
 *
 * Ids, counts and closed codes only. The table it is stored in is not encrypted, and the channel is
 * a websocket — neither is a place for a counterparty or an amount, and the bell needs neither: it
 * says what happened and links to the import, which is read the usual way.
 *
 * On the person's devices too, when their switch for imports is on (`statement-imports`): the same
 * facts — counts and what happened — in the device's language, opening the same import.
 */
final class StatementImportNotice extends Notification implements SendsPush
{
    /** The kind declared for this notice (`TaxRomaniaServiceProvider::declareNotificationTypes()`). */
    public const string PUSH_TYPE = 'statement-imports';

    public function __construct(
        private readonly StatementImport $import,
        private readonly ImportNoticeKind $kind,
        private readonly int $rowsSuggested = 0,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', WebPushChannel::class];
    }

    public function pushType(): string
    {
        return self::PUSH_TYPE;
    }

    public function toPush(object $notifiable, string $locale): PushMessage
    {
        $kind = $this->kind->value;
        $counts = [
            'rows' => (string) $this->import->row_count,
            'imported' => (string) $this->import->imported_count,
            'suggested' => (string) $this->rowsSuggested,
        ];

        return new PushMessage(
            title: __("tax-romania::notifications.imports.{$kind}.title", [], $locale),
            body: __("tax-romania::notifications.imports.{$kind}.body", $counts, $locale),
            url: '/wallet/imports/new?import=' . rawurlencode($this->import->id),
            tag: 'import-' . $this->import->id,
        );
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
