<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * `documents.source` (`BLUEPRINT.md` §6.6). Only `USER_UPLOAD` is ever produced by this task's code
 * (the PWA capture flow, §14.1) — `SPV`/`IMPORT`/`SYSTEM` are declared per the blueprint's literal
 * schema (extensibility through the enum, not speculative logic, `BLUEPRINT.md` §2.2's pattern
 * applied here) for the later milestones that will produce documents that way (M10 SPV inbox
 * sync, a future bank-statement importer).
 */
enum DocumentSource: string
{
    case USER_UPLOAD = 'USER_UPLOAD';
    case SPV = 'SPV';
    case IMPORT = 'IMPORT';
    case SYSTEM = 'SYSTEM';
}
