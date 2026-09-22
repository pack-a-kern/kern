<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Fiscal parameters
|--------------------------------------------------------------------------
|
| The values a tax is computed from — a rate, the minimum wage, a threshold counted in minimum wages
| — and where each is read. Nothing here is a value: every one is read from an official text, looked
| for in the law, saved as a candidate with what it was read from, and counts only once a person has
| approved it (`App\Domain\TaxKnowledge\FiscalParameters`).
|
| Two sources say what a value is:
|
|  - ANAF's structure file for each year's declaration (the D212): the formula of every field,
|    "cass_baza = 6*3300", which says which values that year's declaration applies. Read as ANAF
|    publishes it; only its address and hash are kept.
|  - The Government's decision setting the minimum wage, read with the rest of the legislation.
|
| And one says where the value is law: the Codul fiscal, in the consolidation in force on the first
| day of the year, searched for the paragraph that says the same.
*/

return [

    // The law a value is looked for in, as it is filed (`ext_source_documents.external_identifier`).
    'law' => env('FISCAL_PARAMETERS_LAW', 'LEGE 227/2015'),

    'currency' => env('FISCAL_PARAMETERS_CURRENCY', 'RON'),

    // How long a reading of the values may hold the lock that keeps two from running at once — the
    // task from the screen, and the one a newly read minimum-wage decision starts.
    'lock_seconds' => (int) env('FISCAL_PARAMETERS_LOCK_SECONDS', 900),

    'forms' => [
        'D212' => [
            'source' => 'ANAF',
            // The page ANAF lists every year's D212 files on, and a structure file's link there.
            'index_url' => env('FISCAL_PARAMETERS_D212_INDEX_URL', 'https://static.anaf.ro/static/10/Anaf/Declaratii_R/declaratie_unica.html'),
            'link' => '~href="([^"]*structura[^"]*\.pdf)"~i',
            // The year a file is for: in its name — "structura_declaratieUnica_2024_…" — or, for the
            // first one, only in its heading, "pentru an = 2018". The name wins: the 2022 file's
            // heading still says 2021.
            'year_in_name' => '~structura_(?:D212_|declaratieUnica_)(20\d{2})_~i',
            'year_in_text' => '~pentru\s+an(?:_r)?\s*=\s*(20\d{2})~i',
        ],
    ],

    'minimum_wage' => [
        'parameter' => 'RO.MIN_WAGE',
        'document_type' => 'HOTARARE',
        // The decisions' own title, as a search reads it (`SectionText::searchable()`).
        'title' => 'pentru stabilirea salariului de baza minim brut pe tara garantat in plata',
        // "Începând cu data de 1 ianuarie 2025, salariul de bază minim brut … la suma de 4.050 lei
        // lunar" — or "Începând cu data intrării în vigoare a prezentei hotărâri", which is the
        // decision's own day in force. `{date}` is `legislation.notes.fragments.date`.
        'amount' => '/Începând\s+cu\s+(?:data\s+de\s+(?<date>{date})|data\s+intrării\s+în\s+vigoare)[\s\S]*?(?<amount>\d{1,3}(?:\.\d{3})+|\d{3,5})\s+lei\s+lunar/iu',
    ],

    /*
     * Every value looked for. `type` is how it is stored (`FiscalParameterValueType`): a rate as a
     * decimal, a threshold as a number of minimum wages, several thresholds as a list.
     *
     * `law.within` is the part of the Codul fiscal to search, from its table of contents; `law.says`
     * is what the paragraph says, as a search reads it, `{value}` standing for the value — a rate
     * in percent, a threshold in minimum wages. Romanian writes "24 de salarii" from twenty on.
     *
     * `form` are the D212 formulas that give it. A group names what it captures: `percent`, or
     * `wages` — how many minimum wages — with the `wage` the formula counts them in, or an `amount`
     * in lei that is that many wages. `years` is which year a formula is about, counted from the
     * file's: a declaration reports last year's income and estimates this year's. Where a formula
     * names a wage, the year whose minimum wage on 1 January it is decides between them.
     */
    'parameters' => [
        'RO.MIN_WAGE' => ['type' => 'MONEY'],

        'RO.INCOME_TAX.RATE' => [
            'type' => 'DECIMAL',
            'law' => ['within' => 'Titlul IV', 'says' => 'cota de impozit este de {value}'],
            'form' => [
                ['pattern' => '/estr_impozit_venit\s*=\s*(?<percent>\d+)\s*\/\s*100/i', 'years' => [-1]],
                ['pattern' => '/real_venit_impozit\s*\*\s*(?<percent>\d+)\s*\/\s*100/i', 'years' => [-1]],
                ['pattern' => '/estn_impozit\s*=\s*(?<percent>\d+)\s*\/\s*100/i', 'years' => [0]],
            ],
        ],

        'RO.CAS.RATE' => [
            'type' => 'DECIMAL',
            'law' => ['within' => 'Titlul V Capitolul II', 'says' => '{value} datorata de catre persoanele fizice'],
            'form' => [
                ['pattern' => '/cas_baza\s*\*\s*(?<percent>\d+)\s*\/\s*100/i', 'years' => [-1]],
                ['pattern' => '/(?<percent>\d+)\s*\/\s*100\s*\*\s*cas_ven_ales/i', 'years' => [0]],
            ],
        ],

        'RO.CASS.RATE' => [
            'type' => 'DECIMAL',
            'law' => ['within' => 'Titlul V Capitolul III', 'says' => 'cota de contributie de asigurari sociale de sanatate este de {value}'],
            'form' => [
                ['pattern' => '/cass_baza\s*\*\s*(?<percent>\d+)\s*\/\s*100/i', 'years' => [-1]],
                ['pattern' => '/(?<percent>\d+)\s*\/\s*100\s*\*\s*6\s*\*\s*salariul\s+minim/i', 'years' => [0]],
            ],
        ],

        // The thresholds of the income CAS is paid on: at least 12 minimum wages, and from 2023 a
        // second band from 24.
        'RO.CAS.BASE_WAGES' => [
            'type' => 'JSON',
            'law' => ['within' => 'Titlul V Capitolul II', 'says' => '{value} (?:de )?salarii minime brute pe tara'],
            'form' => [
                ['pattern' => '/cas_baza\s*>=\s*(?<wages>\d+)\s*\*\s*(?<wage>\d{4})/i', 'years' => [-1, 0]],
                ['pattern' => '/plata\s+CAS\s*\(\s*>=\s*(?<amount>\d{1,3}(?:\.\d{3})+)\s*lei\s*\)/i', 'years' => [0]],
                ['pattern' => '/(?<wages>\d+)\s+salarii\s+minime\s+brute\s+pe\s+[ţț]ar[ăa],\s*[îi]n\s+vigoare\s+[îi]n\s+anul\s+pentru\s+care\s+se\s+datoreaz[ăa]\s+contribu[ţț]ia\s*\((?<amount>[\d.\s]+?)\s*lei\)/iu', 'years' => [-1, 0]],
            ],
        ],

        // The bands of the income CASS is paid on, from 2023: 6, 12 and 24 minimum wages.
        'RO.CASS.BASE_WAGES' => [
            'type' => 'JSON',
            'law' => ['within' => 'Titlul V Capitolul III', 'says' => '{value} (?:de )?salarii minime brute pe tara'],
            'form' => [
                // ANAF's own spelling of the third, "csas_baza", included.
                ['pattern' => '/c(?:ass|sas)_baza\s*=\s*(?<wages>\d+)\s*\*\s*(?<wage>\d{4})/i', 'years' => [-1, 0]],
            ],
        ],

        // The most the CASS base can be: 12 minimum wages until 2022, 60 from 2024.
        'RO.CASS.BASE_CAP_WAGES' => [
            'type' => 'INTEGER',
            'law' => ['within' => 'Titlul V Capitolul III', 'says' => '{value} (?:de )?salarii minime brute pe tara'],
            'form' => [
                // "950 <=cass_baza <=22800": the upper bound, in lei.
                ['pattern' => '/\d{3,4}\s*<=?\s*cass_baza\s*<=?\s*(?<amount>\d{5,6})\b/i', 'years' => [-1]],
                ['pattern' => '/baza_cass_estim_B\s*<=\s*(?<wages>\d+)\s*\*\s*(?<wage>\d{4})/i', 'years' => [0]],
            ],
        ],

        // The CASS base of a person with no income who opts to pay it.
        'RO.CASS.NO_INCOME_BASE_WAGES' => [
            'type' => 'INTEGER',
            'law' => ['within' => 'Titlul V Capitolul III', 'says' => '{value} (?:de )?salarii minime brute pe tara'],
            'form' => [
                ['pattern' => '/\d+\s*\/\s*100\s*\*\s*(?<wages>\d+)\s*\*\s*salariul\s+minim\s*\(\s*(?<wage>\d{4})\s*\)/i', 'years' => [0]],
            ],
        ],
    ],
];
