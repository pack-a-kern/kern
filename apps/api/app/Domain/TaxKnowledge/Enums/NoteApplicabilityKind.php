<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Enums;

/**
 * What a sentence in one of the portal's notes says about when a provision applies — and so which
 * date it gives. The words that say it are `config/legislation.php`'s `notes.applicability`.
 */
enum NoteApplicabilityKind: string
{
    /** "se aplică începând cu veniturile aferente lunii august 2025" — from the first of that month. */
    case INCOME_MONTH = 'income_month';

    /**
     * "…veniturile aferente lunii următoare publicării în Monitorul Oficial … a prezentei ordonanțe"
     * — from the first of the month after the act the note quotes was published.
     */
    case INCOME_MONTH_AFTER_PUBLICATION = 'income_month_after_publication';

    /** "…veniturile aferente anului 2026" — from the first of January of that year. */
    case INCOME_YEAR = 'income_year';

    /** "se aplică începând cu anul fiscal 2026" — from the first of January of that year. */
    case FISCAL_YEAR = 'fiscal_year';

    /**
     * "…anul fiscal modificat care începe în anul 2026" — a company's own fiscal year, which starts
     * on a day the note does not say: the year alone, no date.
     */
    case MODIFIED_FISCAL_YEAR = 'modified_fiscal_year';

    /** "începând cu trimestrul I 2026" — from the first day of that quarter. */
    case QUARTER = 'quarter';

    /** "intră în vigoare la data de 31 martie 2026". */
    case IN_FORCE = 'in_force';

    /** "intră în vigoare la data publicării" — the day the act the note quotes was published. */
    case IN_FORCE_ON_PUBLICATION = 'in_force_on_publication';

    /** "începând cu data de 1 iulie 2024 [și până la data de 31 decembrie 2025 inclusiv]". */
    case FROM_DATE = 'from_date';

    /** "…se suspendă până la data de 31 decembrie 2022" — an end, with no start. */
    case UNTIL_DATE = 'until_date';

    /** "la data de 1 ianuarie 2024 se abrogă…" — a date, whatever happens on it. */
    case ON_DATE = 'on_date';
}
