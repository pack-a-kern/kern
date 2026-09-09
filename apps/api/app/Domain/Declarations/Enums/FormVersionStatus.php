<?php

declare(strict_types=1);

namespace App\Domain\Declarations\Enums;

/**
 * `form_versions.status` — this project's own content-readiness governance, not an ANAF concept.
 * `DRAFT` while its schema/applicability data is still being assembled; `ACTIVE` once it is
 * trustworthy enough to back a new `declarations` draft
 * (`App\Domain\Declarations\Services\DeclarationValidator`); `RETIRED` once superseded by a later
 * version — a `RETIRED` version may still back historical declarations already filed against it
 * (`BLUEPRINT.md` §38-A: recomputing a past year must never require rewriting history), it simply
 * cannot start a new one.
 */
enum FormVersionStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case RETIRED = 'RETIRED';
}
