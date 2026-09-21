<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Enums;

/**
 * The moments an import is worth telling someone about.
 *
 * Only the ones where they have to act, or where waiting is over: a statement takes minutes to read
 * and to classify, and nobody should have to keep the page open to find out it is their turn.
 */
enum ImportNoticeKind: string
{
    /** Read; the columns are waiting to be confirmed. */
    case READY_FOR_MAPPING = 'READY_FOR_MAPPING';

    /** The model has finished suggesting; the rows are waiting to be gone through. */
    case READY_FOR_REVIEW = 'READY_FOR_REVIEW';

    case IMPORTED = 'IMPORTED';

    case FAILED = 'FAILED';
}
