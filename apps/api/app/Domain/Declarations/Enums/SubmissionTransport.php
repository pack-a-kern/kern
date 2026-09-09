<?php

declare(strict_types=1);

namespace App\Domain\Declarations\Enums;

/**
 * `declaration_submissions.transport` (`BLUEPRINT.md` §6.10/§13, literal values). `SPV_BROWSER` and
 * `ANAF_API` name the two transports `docs/reference/anaf-spv-integration.md` describes (browser
 * automation for an ordinary PF user; a documented API only for a qualified-certificate holder —
 * still unbuilt, M10); `MANUAL` records a human filing the artifact through the portal by hand.
 * This module never chooses a transport itself — it only records which one a caller used.
 */
enum SubmissionTransport: string
{
    case SPV_BROWSER = 'SPV_BROWSER';
    case ANAF_API = 'ANAF_API';
    case MANUAL = 'MANUAL';
}
