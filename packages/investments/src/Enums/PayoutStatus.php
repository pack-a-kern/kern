<?php

declare(strict_types=1);

namespace Kern\Investments\Enums;

enum PayoutStatus: string
{
    case ANNOUNCED = 'ANNOUNCED';
    case PAID = 'PAID';
}
