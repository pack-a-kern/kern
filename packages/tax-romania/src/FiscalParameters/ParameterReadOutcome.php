<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters;

/**
 * What came of reading the law for one value and one year, where no value came of it.
 *
 * A year with no value is two different things, and a screen that shows both as an empty cell says
 * neither: the law of that year may not state the value at all — rent had no quota of expenses in
 * 2023 — or nothing may have been asked, because no model could be reached. The first is an answer;
 * the second is work still to do.
 */
enum ParameterReadOutcome: string
{
    /** The law of that year was read, and does not state this value. */
    case NOT_IN_LAW = 'NOT_IN_LAW';

    /** No model could be asked: none configured, or the provider refused. */
    case UNREAD = 'UNREAD';
}
