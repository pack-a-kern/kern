<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters;

/**
 * What was found out about a value while it was read, for the person who approves it — each one
 * shown in their language.
 */
enum ParameterCheck: string
{
    /** The law has more than one paragraph that says it; the first is its source until one is picked. */
    case SEVERAL_PARAGRAPHS = 'several_paragraphs';

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
