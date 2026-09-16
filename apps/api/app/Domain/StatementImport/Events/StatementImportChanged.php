<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Events;

use App\Domain\StatementImport\Enums\StatementImportStatus;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An import moved, and the screen watching it should look again.
 *
 * ## Why this exists
 *
 * Reading a statement is four jobs with a person in the middle, and the screen was learning about
 * them by asking every few seconds — but only while the status was `READING` or `IMPORTING`. The
 * counterparty classification finishes during `REVIEW`, which nothing was watching, so a person's
 * categories and tags arrived in the database and never on their screen unless they happened to
 * change the filter. Polling states you have thought of is not a substitute for being told.
 *
 * ## What travels
 *
 * The import's id, where it has got to, and whether this was the classification finishing. Nothing
 * about a row: not an amount, not a counterparty, not a count that would say how much a person
 * spent. The payload is "something changed, ask again" and the answer comes back over HTTPS through
 * the tenant-scoped endpoint, exactly as the documents channel works.
 */
final class StatementImportChanged implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $importId,
        public readonly string $taxSubjectId,
        public readonly StatementImportStatus $status,
        /** Whether the counterparty proposals were just written, rather than the status moving. */
        public readonly bool $proposals = false,
    ) {
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tax-subjects.{$this->taxSubjectId}.imports")];
    }

    public function broadcastAs(): string
    {
        return 'StatementImportChanged';
    }

    /** @return array{import_id: string, status: string, proposals: bool} */
    public function broadcastWith(): array
    {
        return [
            'import_id' => $this->importId,
            'status' => $this->status->value,
            'proposals' => $this->proposals,
        ];
    }
}
