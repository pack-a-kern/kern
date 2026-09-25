<?php

declare(strict_types=1);

namespace Kern\Documents\Enums;

/**
 * `documents.status` (`BLUEPRINT.md` §6.6/§14.1). The lifecycle is linear and one-way:
 *
 *     QUARANTINE -> SCANNED -> PROCESSING -> READY
 *     QUARANTINE -> REJECTED
 *     PROCESSING -> REJECTED (`Kern\Documents\Jobs\ProcessScannedDocument` — the
 *                              text-extraction service reported one of its closed set of "this
 *                              document is bad" outcomes, e.g. an encrypted PDF; see
 *                              `Kern\Documents\Extraction\Exceptions\
 *                              DocumentRejectedByExtractionException` and
 *                              `docs/handoff/024-extraction-endpoint.md`)
 *
 * No code in this module ever moves a document backwards. A document stuck in `QUARANTINE` means
 * its virus scan has not yet completed successfully — see `Kern\Documents\Scanning\
 * ClamdVirusScanner`'s docblock for why an unreachable/erroring scanner leaves it there rather
 * than advancing it.
 */
enum DocumentStatus: string
{
    case QUARANTINE = 'QUARANTINE';
    case SCANNED = 'SCANNED';
    case PROCESSING = 'PROCESSING';
    case READY = 'READY';
    case REJECTED = 'REJECTED';
}
