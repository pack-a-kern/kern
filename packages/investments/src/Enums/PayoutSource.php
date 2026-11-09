<?php

declare(strict_types=1);

namespace Kern\Investments\Enums;

/** Where a payout was read: a Ministry of Finance order on legislatie.just.ro, or BVB's pages. */
enum PayoutSource: string
{
    case ORDER = 'ORDER';
    case BVB = 'BVB';
}
