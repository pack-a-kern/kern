<?php

declare(strict_types=1);

namespace App\Domain\Documents\Scanning;

/** The only two outcomes a scan may report — never a raw signature name (that would be a log/PII surface). */
enum ScanOutcome
{
    case CLEAN;
    case INFECTED;
}
