<?php

declare(strict_types=1);

namespace Kern\Investments\Enums;

/** What a security is (ADR 0025 §2). No other kind until a source brings one. */
enum InstrumentKind: string
{
    case GOVERNMENT_BOND = 'GOVERNMENT_BOND';
    case CORPORATE_BOND = 'CORPORATE_BOND';
    case SHARE = 'SHARE';
}
