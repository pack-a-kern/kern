<?php

declare(strict_types=1);

namespace Kern\Legislation\Requirements;

/**
 * How much of the installation rests on a declared requirement (ADR 0027).
 *
 * The tier is a claim the declaring package owns, reviewed as its configuration is: a package that
 * declares `REQUIRED` for a law it cannot satisfy would wall the whole application.
 */
enum RequirementTier: string
{
    /** The application is not usable without it — unmet means the install is not bootstrapped. */
    case REQUIRED = 'required';

    /** One feature rests on it; the rest of the app does not — unmet means that feature is unavailable. */
    case FEATURE = 'feature';

    /** Better with it, correct without it — unmet is a note, never a block. */
    case OPTIONAL = 'optional';
}
