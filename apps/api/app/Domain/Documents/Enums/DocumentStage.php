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
 * So this is a presentation of the pipeline state plus one fact the pipeline does not hold: whether
 * anybody has acted on the document yet. Derived on the server rather than in the client, because
 * "acted on" means an accepted reading *or* a transaction made from it, and that rule already
 * exists in one place — the inbox's `needs_review` filter — and must not grow a second version.
 */
enum DocumentStage: string
{
    /** Uploaded; the virus scan has not cleared it yet. Nothing else may touch it. */
    case CHECKING = 'CHECKING';

    /** Clean and waiting its turn. Reading is serialised, so a queue here is normal. */
    case QUEUED = 'QUEUED';

    /** Being read right now. */
    case PROCESSING = 'PROCESSING';

    /** Read, and waiting on a person — whether or not anything was extracted from it. */
    case ACTION_NEEDED = 'ACTION_NEEDED';

    /** A person has accepted a reading or made a transaction from it. Nothing left to do. */
    case DONE = 'DONE';

    /** Refused, and terminal: infected, unreadable, or a type this app does not accept. */
    case REJECTED = 'REJECTED';

    /**
     * @param  bool  $settled  whether anybody has accepted a reading of this document or built a
     *                         transaction from it
     */
    public static function of(DocumentStatus $status, bool $settled): self
    {
        return match ($status) {
            DocumentStatus::QUARANTINE => self::CHECKING,
            DocumentStatus::SCANNED => self::QUEUED,
            DocumentStatus::PROCESSING => self::PROCESSING,
            DocumentStatus::REJECTED => self::REJECTED,
            DocumentStatus::READY => $settled ? self::DONE : self::ACTION_NEEDED,
        };
    }

    /** Whether the pipeline still has work to do, so a screen knows to expect another event. */
    public function isInFlight(): bool
    {
        return $this === self::CHECKING || $this === self::QUEUED || $this === self::PROCESSING;
    }
}
