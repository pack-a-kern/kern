<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * Where a document stands, in the terms the person who uploaded it would use.
 *
 * {@see DocumentStatus} is the pipeline's own state and stays exactly as it is — it is what the
 * jobs read and write, and it is correct. It is just not what anyone wants to be told: `SCANNED`
 * means "the virus scan passed and the reading has not started", which on screen should say
 * "waiting to be read". And `READY` — the pipeline's "I am finished with this" — read as though
 * the *document* were finished, when it is the moment a person's work begins.
 *
 * So this is a presentation of the pipeline state plus one fact the pipeline does not hold: how far
 * a person has got with the document. That second half is {@see TransactionImportState}, which is
 * the one place the rule lives — the badge, the inbox filter and the import button are all read off
 * it, so they cannot drift into telling three different stories about the same document.
 */
enum DocumentStage: string
{
    /** Uploaded; the virus scan has not cleared it yet. Nothing else may touch it. */
    case CHECKING = 'CHECKING';

    /** Clean and waiting its turn. Reading is serialised, so a queue here is normal. */
    case QUEUED = 'QUEUED';

    /** Being read right now. */
    case PROCESSING = 'PROCESSING';

    /** Read, and the reading is still a proposal: somebody has to accept or correct it. */
    case ACTION_NEEDED = 'ACTION_NEEDED';

    /**
     * Agreed to, and not yet in the ledger.
     *
     * Split out of `ACTION_NEEDED`, which used to cover both halves of the review — a document
     * nobody had looked at and one already accepted looked identical in a list, so it could not
     * tell you which of the two things you were being asked to do.
     */
    case TO_IMPORT = 'TO_IMPORT';

    /** Its transactions exist. Nothing left to do. */
    case DONE = 'DONE';

    /** Refused, and terminal: infected, unreadable, or a type this app does not accept. */
    case REJECTED = 'REJECTED';

    public static function of(DocumentStatus $status, TransactionImportState $import): self
    {
        return match ($status) {
            DocumentStatus::QUARANTINE => self::CHECKING,
            DocumentStatus::SCANNED => self::QUEUED,
            DocumentStatus::PROCESSING => self::PROCESSING,
            DocumentStatus::REJECTED => self::REJECTED,
            // `UNAVAILABLE` cannot reach here — it is what the import state says *because* the
            // document is not `READY` — but a match must be total, and "waiting on a person" is the
            // honest answer for a state this enum was not told how to name.
            DocumentStatus::READY => match ($import) {
                TransactionImportState::IMPORTED => self::DONE,
                TransactionImportState::AVAILABLE => self::TO_IMPORT,
                TransactionImportState::AWAITING_ACCEPTANCE, TransactionImportState::UNAVAILABLE => self::ACTION_NEEDED,
            },
        };
    }

    /** Whether the pipeline still has work to do, so a screen knows to expect another event. */
    public function isInFlight(): bool
    {
        return $this === self::CHECKING || $this === self::QUEUED || $this === self::PROCESSING;
    }
}
