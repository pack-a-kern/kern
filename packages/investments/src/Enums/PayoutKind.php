<?php

declare(strict_types=1);

namespace Kern\Investments\Enums;

/** What a payment per unit is: interest, the repayment of the face value, or a share of the profit. */
enum PayoutKind: string
{
    case COUPON = 'COUPON';
    case PRINCIPAL = 'PRINCIPAL';
    case DIVIDEND = 'DIVIDEND';
}
