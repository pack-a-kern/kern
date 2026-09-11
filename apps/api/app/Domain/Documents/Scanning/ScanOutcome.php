<?php

declare(strict_types=1);

namespace App\Domain\Documents\Scanning;

/**
 * The only two outcomes a scan may report — never a raw signature name, which is attacker-influenced
 * text and would be a log/PII surface (`BLUEPRINT.md` §4.10).
 *
 * String-backed so it can be persisted as-is on `document_scans.outcome`. The values are the case
 * names, so a row reads the same as the code does; there is no mapping to get wrong.
 */
enum ScanOutcome: string
{
    case CLEAN = 'CLEAN';
    case INFECTED = 'INFECTED';
}
