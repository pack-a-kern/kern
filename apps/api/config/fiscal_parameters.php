<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Fiscal parameters
|--------------------------------------------------------------------------
|
| The values a tax is computed from — a rate, the minimum wage, a threshold counted in minimum wages
| — and what is asked of the law for each. Nothing here is a value: every one is read from an
| official text, saved as a candidate with the paragraph it was read from, and counts only once a
| person has approved it (`App\Domain\TaxKnowledge\FiscalParameters`).
|
| Two texts say what a value is:
|
|  - the Codul fiscal, in the wording in force when the value starts: the paragraph is read for the
|    answer to this parameter's question, and the answer carries the sentence it rests on;
|  - the Government's decision setting the minimum wage, read with the rest of the legislation.
|
| The questions are written here, once, in the words of the law. They are what a model is asked —
| never what it may invent: an answer counts only if the sentence it quotes stands, word for word,
| in the paragraph it names, and that paragraph is in force that year.
*/

return [

    // The law a value is looked for in, as it is filed (`ext_source_documents.external_identifier`).
    'law' => env('FISCAL_PARAMETERS_LAW', 'LEGE 227/2015'),

    'currency' => env('FISCAL_PARAMETERS_CURRENCY', 'RON'),

    // How long a reading of the values may hold the lock that keeps two from running at once — the
    // task from the screen, and the one a newly read minimum-wage decision starts.
    'lock_seconds' => (int) env('FISCAL_PARAMETERS_LOCK_SECONDS', 900),

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
     * Every value looked for, and what is asked of the law for it.
     *
     * `subject` is who it is about: what a PERSON owes through their own declaration, what a COMPANY
     * owes, or BOTH. `law.within` is the part of the Codul fiscal it is read from, from its table of
     * contents; `law.question` is the question, in Romanian, as the law puts it; `law.answer` is the
     * shape of the answer ({@see App\Domain\TaxKnowledge\FiscalParameters\ParameterAnswer}), which
     * is also what the answer is checked against.
     *
     * A question names the income it is about — "activități independente", "art. 155 alin. (1) lit.
     * c)-h)" — because the law sets a different base for each, and a question that does not say
     * which is answered with whichever the reader meets first.
     */
    'parameters' => [
        'RO.MIN_WAGE' => ['type' => 'MONEY', 'subject' => 'PERSON'],

        'RO.INCOME_TAX.RATE' => [
            'type' => 'DECIMAL',
            'subject' => 'PERSON',
            'law' => [
                'within' => 'Titlul IV',
                'question' => 'Care este cota de impozit pe venit care se aplică veniturilor persoanelor fizice?',
                'answer' => 'percent',
            ],
        ],

        'RO.CAS.RATE' => [
            'type' => 'DECIMAL',
            'subject' => 'PERSON',
            'law' => [
                'within' => 'Titlul V Capitolul II',
                'question' => 'Care este cota contribuției de asigurări sociale datorată de persoanele fizice?',
                'answer' => 'percent',
            ],
        ],

        'RO.CASS.RATE' => [
            'type' => 'DECIMAL',
            'subject' => 'PERSON',
            'law' => [
                'within' => 'Titlul V Capitolul III',
                'question' => 'Care este cota contribuției de asigurări sociale de sănătate?',
                'answer' => 'percent',
            ],
        ],

        // The income CAS is owed on: at least 12 minimum wages, and from 2023 a second band from 24.
        'RO.CAS.BASE_WAGES' => [
            'type' => 'JSON',
            'subject' => 'PERSON',
            'law' => [
                'within' => 'Titlul V Capitolul II',
                'question' => 'Care sunt nivelurile bazei anuale de calcul al contribuției de asigurări sociale pentru veniturile din activități independente și din drepturi de proprietate intelectuală? Enumeră toate nivelurile.',
                'answer' => 'wages_list',
            ],
        ],

        // CASS on the income of art. 155 alin. (1) lit. c)-h) — intellectual property, rent,
        // dividends, interest, investments, agriculture, other: not the income itself but the band
        // of it reached.
        'RO.CASS.OTHER_INCOME.BANDS_WAGES' => [
            'type' => 'JSON',
            'subject' => 'PERSON',
            'law' => [
                'within' => 'Titlul V Capitolul III',
                'question' => 'Care sunt nivelurile bazei anuale de calcul al contribuției de asigurări sociale de sănătate pentru veniturile prevăzute la art. 155 alin. (1) lit. c)-h)? Enumeră toate nivelurile.',
                'answer' => 'wages_list',
            ],
        ],

        // CASS on independent activities' income, lit. b): the net income itself, counted between a
        // floor and a cap.
        'RO.CASS.INDEPENDENT.CAP_WAGES' => [
            'type' => 'INTEGER',
            'subject' => 'PERSON',
            'law' => [
                'within' => 'Titlul V Capitolul III',
                'question' => 'Care este nivelul MAXIM al bazei anuale de calcul al contribuției de asigurări sociale de sănătate pentru veniturile din activități independente (art. 155 alin. (1) lit. b))?',
                'answer' => 'wages',
            ],
        ],

        'RO.CASS.INDEPENDENT.FLOOR_WAGES' => [
            'type' => 'INTEGER',
            'subject' => 'PERSON',
            'law' => [
                'within' => 'Titlul V Capitolul III',
                'question' => 'Care este nivelul MINIM al bazei anuale de calcul al contribuției de asigurări sociale de sănătate datorate de persoanele care realizează venituri din activități independente sub acest nivel?',
                'answer' => 'wages',
            ],
        ],

        // Until 2022, the base of every income together: 12 minimum wages.
        'RO.CASS.ALL_INCOME.CAP_WAGES' => [
            'type' => 'INTEGER',
            'subject' => 'PERSON',
            'law' => [
                'within' => 'Titlul V Capitolul III',
                // As the law put it until 2022, when one base held for every income together. The
                // question quotes it, so a year whose law says no such thing answers nothing —
                // Art. 170 alin. (4) was not withdrawn in 2023, it was rewritten into the bands.
                'question' => 'Baza anuală de calcul al contribuției de asigurări sociale de sănătate, pentru toate veniturile cumulate, o reprezintă echivalentul a câte salarii minime brute pe țară?',
                'answer' => 'wages',
            ],
        ],

        // The CASS base of a person with no income who opts to pay it.
        'RO.CASS.NO_INCOME_BASE_WAGES' => [
            'type' => 'INTEGER',
            'subject' => 'PERSON',
            'law' => [
                'within' => 'Titlul V Capitolul III',
                'question' => 'Care este baza de calcul al contribuției de asigurări sociale de sănătate pentru persoanele fizice fără venituri care optează pentru plata contribuției?',
                'answer' => 'wages',
            ],
        ],

        // What is deducted from the gross before the tax and the contributions are counted.
        'RO.IP_INCOME.EXPENSE_QUOTA' => [
            'type' => 'DECIMAL',
            'subject' => 'PERSON',
            'law' => [
                'within' => 'Titlul IV',
                'question' => 'Ce cotă forfetară de cheltuieli se deduce din venitul brut din drepturi de proprietate intelectuală?',
                'answer' => 'percent',
            ],
        ],

        'RO.RENT_INCOME.EXPENSE_QUOTA' => [
            'type' => 'DECIMAL',
            'subject' => 'PERSON',
            'law' => [
                'within' => 'Titlul IV',
                'question' => 'Ce cotă forfetară de cheltuieli se deduce din venitul brut din cedarea folosinței bunurilor (chirii)?',
                'answer' => 'percent',
            ],
        ],

        // Pensions: the part free of tax, and the part above which health contribution is owed.
        'RO.PENSION.TAX_FREE_AMOUNT' => [
            'type' => 'MONEY',
            'subject' => 'PERSON',
            'law' => [
                'within' => 'Titlul IV',
                'question' => 'Care este suma neimpozabilă lunară care se deduce din venitul din pensii înainte de aplicarea impozitului?',
                'answer' => 'lei',
            ],
        ],

        'RO.PENSION.CASS_THRESHOLD_AMOUNT' => [
            'type' => 'MONEY',
            'subject' => 'PERSON',
            'law' => [
                'within' => 'Titlul V Capitolul III',
                // The law asked this two ways: until July 2025 the part of a pension above 4.000 lei
                // was owed, and since Legea 141/2025 a sum is deducted from the pension instead.
                'question' => 'Care este suma lunară care se deduce din venitul din pensie la stabilirea bazei de calcul al contribuției de asigurări sociale de sănătate, sau suma peste care venitul din pensii se cuprinde în această bază?',
                'answer' => 'lei',
            ],
        ],
    ],
];
