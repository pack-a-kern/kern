<?php

declare(strict_types=1);

namespace Kern\Legislation\Retrieval\Query;

/**
 * The unknown-date rule (`docs/handoff/029-hybrid-retrieval.md`, `docs/reference/legislation-effective-dates.md`):
 * a result whose effective dates are genuinely unset (`REQUIRES_REVIEW`, most current versions —
 * see that reference doc) must never be silently treated as either "definitely applies" or
 * "definitely doesn't apply" for the requested date. `Kern\Legislation\Retrieval\Query\HybridLegislationSearch`
 * computes this per row and never returns a third, invisible case: a row whose known dates place
 * it *outside* the requested date is excluded from the result entirely (never reaches this enum),
 * because that is a genuine, confirmed non-match — not something to surface as "unknown".
 */
enum TemporalConfidence: string
{
    /** The resolved effective range (section's own, or inherited from its document version) covers the requested date. */
    case CONFIRMED = 'CONFIRMED';

    /** Both the section and its document version have no effective dates set — applicability for the requested date cannot be determined. */
    case DATE_UNKNOWN = 'DATE_UNKNOWN';
}
