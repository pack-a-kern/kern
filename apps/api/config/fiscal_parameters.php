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
     * Every value looked for, by the tax it belongs to.
     *
     * A tax is read from one part of the law — everything about the income tax is Titlul IV, all of
     * CAS is Titlul V Capitolul II — so `law.within` is written once for the tax and narrowed by a
     * rule only where a narrower part answers it better: the rate stands in the general chapter,
     * while a chapter of nine hundred provisions answers anything and nothing.
     *
     * A rule's code is its tax and its own name — `RO.INCOME_TAX.RATE` — which is what a value is
     * stored under and what a calculation asks for.
     *
     * `subject` is who owes it: a PERSON through their own declaration, a COMPANY, or BOTH.
     * `law.question` is what is asked of the law, in Romanian, as the law puts it; `law.answer` is
     * the shape of the answer ({@see App\Domain\TaxKnowledge\FiscalParameters\ParameterAnswer}),
     * which is also what the answer is checked against. A question names the income it is about,
     * because the law sets a different base for each.
     */
    'taxes' => [

        'RO.INCOME_TAX' => [
            'subject' => 'PERSON',
            'law' => ['within' => ['Titlul IV']],

            'rules' => [
                // What the tax is, on every income of a person.
                'RATE' => [
                    'type' => 'DECIMAL',
                    'law' => [
                        'within' => ['Titlul IV Capitolul I'],
                        'question' => 'Care este cota de impozit pe venit care se aplică veniturilor persoanelor fizice?',
                        'answer' => 'percent',
                    ],
                ],

                // What is taken out of the gross before the tax is applied, per kind of income.
                'IP_EXPENSE_QUOTA' => [
                    'type' => 'DECIMAL',
                    'law' => [
                        'question' => 'Ce cotă forfetară de cheltuieli se deduce din venitul brut din drepturi de proprietate intelectuală?',
                        'answer' => 'percent',
                    ],
                ],

                'RENT_EXPENSE_QUOTA' => [
                    'type' => 'DECIMAL',
                    'law' => [
                        'question' => 'Ce cotă forfetară de cheltuieli se deduce din venitul brut din cedarea folosinței bunurilor (chirii)?',
                        'answer' => 'percent',
                    ],
                ],

                'PENSION_TAX_FREE' => [
                    'type' => 'MONEY',
                    'law' => [
                        'question' => 'Care este suma neimpozabilă lunară care se deduce din venitul din pensii la stabilirea venitului impozabil lunar din pensii?',
                        'answer' => 'lei',
                    ],
                ],
            ],
        ],

        'RO.CASS' => [
            'subject' => 'PERSON',
            'law' => ['within' => ['Titlul V Capitolul III']],

            'rules' => [
                'RATE' => [
                    'type' => 'DECIMAL',
                    'law' => [
                        'within' => ['Titlul V Capitolul III Secţiunea a 2-a'],
                        'question' => 'Care este cota contribuției de asigurări sociale de sănătate?',
                        'answer' => 'percent',
                    ],
                ],

                // Independent activities, art. 155 alin. (1) lit. b): the base is the income itself,
                // between two levels — the cap stated where the base is, the floor in the section on
                // declaring and paying it, which is why both are read.
                'INDEPENDENT_BASE_WAGES' => [
                    'type' => 'JSON',
                    'law' => [
                        'within' => ['Titlul V Capitolul III Secţiunea a 5-a', 'Titlul V Capitolul III Secţiunea a 6-a'],
                        'question' => 'Între ce niveluri, exprimate în salarii minime brute pe țară, se situează baza anuală de calcul al contribuției de asigurări sociale de sănătate pentru veniturile prevăzute la art. 155 alin. (1) lit. b) — nivelul minim sub care baza nu poate coborî și nivelul maxim peste care nu poate urca?',
                        'answer' => 'wages_range',
                    ],
                ],

                // The other income — intellectual property, rent, dividends, interest, investments,
                // agriculture, other — is not counted itself: the base is the band it reaches. Until
                // 2022 there were no bands, one base held for every income, and that is the rule
                // below: a year with a single level answers this question with nothing.
                'OTHER_INCOME_BANDS_WAGES' => [
                    'type' => 'JSON',
                    'law' => [
                        'within' => ['Titlul V Capitolul III Secţiunea a 5-a', 'Titlul V Capitolul III Secţiunea a 6-a'],
                        'question' => 'Care sunt nivelurile bazei anuale de calcul al contribuției de asigurări sociale de sănătate pentru veniturile prevăzute la art. 155 alin. (1) lit. c)-h), dacă legea prevede mai multe niveluri, după nivelul veniturilor realizate? Enumeră toate nivelurile. Dacă legea prevede un singur nivel pentru toate veniturile, răspunde null.',
                        'answer' => 'wages_list',
                    ],
                ],

                // Until 2022 one base held for every income together. The law stopped saying it, and
                // the years since say so themselves.
                'ALL_INCOME_BASE_WAGES' => [
                    'type' => 'INTEGER',
                    'law' => [
                        'within' => ['Titlul V Capitolul III Secţiunea a 5-a'],
                        'question' => 'Baza anuală de calcul al contribuției de asigurări sociale de sănătate, pentru toate veniturile cumulate, o reprezintă echivalentul a câte salarii minime brute pe țară?',
                        'answer' => 'wages',
                    ],
                ],
            ],
        ],
    ],
];
