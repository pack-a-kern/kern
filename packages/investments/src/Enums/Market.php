<?php

declare(strict_types=1);

namespace Kern\Investments\Enums;

/** Where a security trades. Tezaur trades nowhere and has none. */
enum Market: string
{
    case BVB = 'BVB';
}
