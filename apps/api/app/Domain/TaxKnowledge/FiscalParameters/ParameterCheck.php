<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters;

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

    /** The form counts in a minimum wage that is not the decision's for that year. */
    case WAGE_DIFFERS = 'wage_differs';

    /** A form counts a year's income in this same minimum wage. */
    case CONFIRMED_BY_FORM = 'confirmed_by_form';

    /** A form counts a year's income in a minimum wage no decision set for that year. */
    case DIFFERS_FROM_FORM = 'differs_from_form';

    /** An amount in lei that is not a whole number of that year's minimum wages. Never a value. */
    case NOT_WHOLE_WAGES = 'not_whole_wages';

    /** The law in force that year has no paragraph that says it. Never a value. */
    case NOT_IN_LAW = 'not_in_law';

    /** No minimum wage is known for the year the form is about, so an amount cannot be counted in it. */
    case NO_WAGE_FOR_YEAR = 'no_wage_for_year';

    /** The extraction service would not read the file. */
    case UNREADABLE = 'unreadable';
}
