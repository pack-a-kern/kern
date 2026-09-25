<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters;

/**
 * What was found out about a value while it was read, for the person who approves it — each one
 * shown in their language.
 */
enum ParameterCheck: string
{
    /**
     * The paragraph is read in a later consolidation than the one in force, its wording dated from
     * before by the portal's own note — the years the portal publishes no consolidation for.
     */
    case DATED_BY_NOTE = 'dated_by_note';

    /** The wording that says it came into force during the value's year, not on its first day. */
    case IN_FORCE_LATER = 'in_force_later';

    /**
     * The law has more than one paragraph that says it, and no year of it was approved yet: a person
     * picks the one it rests on, and the other years keep to it.
     */
    case SEVERAL_PARAGRAPHS = 'several_paragraphs';

    /** The paragraph it was approved on for another year does not say this value; others do. */
    case PARAGRAPH_CHANGED = 'paragraph_changed';

    /** Another reading gives a different value for the same period. */
    case CONFLICTING = 'conflicting';

    /** The two models read the law differently; the value is the first one's. */
    case READERS_DISAGREE = 'readers_disagree';

    /** No model could be asked — none configured, or the provider refused. */
    case UNREAD = 'unread';
}
