<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * `document_extractions.status` (`BLUEPRINT.md` §6.6, §14.6). Every row this task's code creates is
 * `PROPOSED` — no code path is permitted to write `ACCEPTED` (`BLUEPRINT.md` §14.6: "AI-extracted
 * values ... never CONFIRMED automat în v1"). `REVIEWED`/`ACCEPTED`/`REJECTED` are reachable only
 * through `App\Domain\Documents\Models\DocumentExtraction`'s own review-transition methods, each
 * of which requires a `reviewed_by` human actor.
 */
enum ExtractionStatus: string
{
    case PROPOSED = 'PROPOSED';
    case REVIEWED = 'REVIEWED';
    case ACCEPTED = 'ACCEPTED';
    case REJECTED = 'REJECTED';
}
