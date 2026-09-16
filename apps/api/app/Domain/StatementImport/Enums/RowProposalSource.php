<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Enums;

/**
 * Where a staged row's proposed category and tags came from.
 *
 * Shown on the review screen beside each row, because "you answered this for this shop last month"
 * deserves more trust than "a model thinks so", and a person skimming three hundred rows needs to
 * see which is which without opening anything.
 */
enum RowProposalSource: string
{
    /** A remembered answer for this counterparty — `counterparty_rules`. */
    case RULE = 'RULE';

    case AI = 'AI';

    /** Typed on the review screen, replacing whatever was proposed. */
    case PERSON = 'PERSON';
}
