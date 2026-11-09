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
| person has approved it (`Kern\TaxRomania\FiscalParameters`).
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

    // Whose calendar a deadline the law sets falls on: the day it is due in Romania, not on the
    // server's clock (UTC) — which is still the day before for the first hours of every day here.
    'timezone' => env('FISCAL_PARAMETERS_TIMEZONE', 'Europe/Bucharest'),

    // How long a reading of the values may hold the lock that keeps two from running at once — the
    // task from the screen, and the one a newly read minimum-wage decision starts.
    'lock_seconds' => (int) env('FISCAL_PARAMETERS_LOCK_SECONDS', 900),

    // The rule whose value is the day the year's declaration is due — what the taxpayer's deadline
    // is counted down to (`Kern\TaxRomania\FiscalParameters\Queries\FilingDeadline`).
    'filing_deadline' => 'RO.INCOME_TAX.FILING_DEADLINE',

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
     * the shape of the answer ({@see Kern\TaxRomania\FiscalParameters\ParameterAnswer}),
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

                // The day the year's Declarația unică is due, in the year after the income: art. 122
                // alin. (3), "până la data de 25 mai inclusiv a anului următor celui de realizare a
                // venitului". Read for each year like any other value, because the law has moved it.
                'FILING_DEADLINE' => [
                    'type' => 'JSON',
                    'law' => [
                        'within' => ['Titlul IV Capitolul XI'],
                        'question' => 'Până la ce dată se completează și se depune Declarația unică privind impozitul pe venit și contribuțiile sociale datorate de persoanele fizice, pentru veniturile realizate într-un an fiscal?',
                        'answer' => 'day_month',
                    ],
                ],
            ],
        ],

        /*
         * Bonds, shares and dividends: income tax, read from its part of the law (Titlul IV Capitolul V),
         * kept apart so the Fiscal parameters screen shows them as a table of their own.
         */
        'RO.INVESTMENTS' => [
            'subject' => 'PERSON',
            'law' => ['within' => ['Titlul IV Capitolul V']],

            'rules' => [
                // Investment income, all of it read in Capitolul V. Public debt — Fidelis, Tezaur,
                // municipal bonds — is art. 93 alin. (1) lit. a), the state's and the unități
                // administrativ-teritoriale's alike. The answer is a yes, because the law states an
                // exemption, not a rate of zero.
                'PUBLIC_DEBT_EXEMPT' => [
                    'type' => 'JSON',
                    'law' => [
                        'within' => ['Titlul IV Capitolul V'],
                        'question' => 'Sunt venituri neimpozabile veniturile realizate din deținerea și transferul instrumentelor financiare care atestă datoria publică a statului, precum și a unităților administrativ-teritoriale?',
                        'answer' => 'yes_no',
                    ],
                ],

                // A gain on securities sold through a Romanian intermediary, withheld by it: one rate
                // for a holding at least as long as the period the law sets, another for a shorter
                // one (art. 97 alin. (8^1) lit. a)). The period is a value of its own, read below.
                'SECURITIES_GAIN_RATE_LONG' => [
                    'type' => 'DECIMAL',
                    'law' => [
                        'within' => ['Titlul IV Capitolul V'],
                        'question' => 'Ce cotă se aplică, prin reținere la sursă, asupra fiecărui câștig din transferul titlurilor de valoare efectuat prin entitățile prevăzute la art. 96^1 alin. (1), pentru titlurile de valoare care au fost dobândite și înstrăinate într-o perioadă cel puțin egală cu perioada de deținere prevăzută de lege?',
                        'answer' => 'percent',
                    ],
                ],

                'SECURITIES_GAIN_RATE_SHORT' => [
                    'type' => 'DECIMAL',
                    'law' => [
                        'within' => ['Titlul IV Capitolul V'],
                        'question' => 'Ce cotă se aplică, prin reținere la sursă, asupra fiecărui câștig din transferul titlurilor de valoare efectuat prin entitățile prevăzute la art. 96^1 alin. (1), pentru titlurile de valoare care au fost dobândite și înstrăinate într-o perioadă mai mică decât perioada de deținere prevăzută de lege?',
                        'answer' => 'percent',
                    ],
                ],

                'SECURITIES_HOLDING_DAYS' => [
                    'type' => 'JSON',
                    'law' => [
                        'within' => ['Titlul IV Capitolul V'],
                        'question' => 'Care este perioada, în zile de la data dobândirii, după care se aplică altă cotă de reținere la sursă asupra câștigului din transferul titlurilor de valoare efectuat prin entitățile prevăzute la art. 96^1 alin. (1)?',
                        'answer' => 'days',
                    ],
                ],

                'DIVIDEND_RATE' => [
                    'type' => 'DECIMAL',
                    'law' => [
                        'within' => ['Titlul IV Capitolul V'],
                        'question' => 'Ce cotă se aplică veniturilor sub formă de dividende, impozitul fiind final?',
                        'answer' => 'percent',
                    ],
                ],
            ],
        ],

        /*
         * CAS is owed on two incomes only — activități independente, and drepturi de proprietate
         * intelectuală or contracte de activitate sportivă (art. 137 alin. (1) lit. b) and b^1)).
         * Nothing else: no rent, no dividends, no interest, which is where CAS and CASS part.
         */
        'RO.CAS' => [
            'subject' => 'PERSON',
            'law' => ['within' => ['Titlul V Capitolul II']],

            'rules' => [
                // Art. 138 states three rates in one article: the person's 25%, and the 4% and 8% an
                // employer owes for hazardous work. The question names who owes it, in the law's own
                // words, because the answer is one number and the wrong one is also in there.
                'RATE' => [
                    'type' => 'DECIMAL',
                    'law' => [
                        'within' => ['Titlul V Capitolul II Secţiunea a 2-a'],
                        'question' => 'Care este cota contribuției de asigurări sociale datorată de persoanele fizice care au calitatea de angajați sau pentru care există obligația plății contribuției de asigurări sociale?',
                        'answer' => 'percent',
                    ],
                ],

                // The base is the income the taxpayer chooses, which may not be below a level: one
                // level until 2022, two from 2023, by the income realised. Unlike CASS there is no
                // cap — the levels are a floor, and a chosen base above them is the base.
                'BASE_MIN_WAGES' => [
                    'type' => 'JSON',
                    'law' => [
                        'within' => ['Titlul V Capitolul II Secţiunea a 5-a'],
                        'question' => 'Care sunt nivelurile sub care nu poate coborî baza anuală de calcul al contribuției de asigurări sociale pentru veniturile din activități independente și din drepturi de proprietate intelectuală, după nivelul veniturilor realizate? Enumeră toate nivelurile; dacă legea prevede un singur nivel pentru toate veniturile, dă acel nivel.',
                        'answer' => 'wages_list',
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

                // Independent activities, art. 155 alin. (1) lit. b). The law has set this base three
                // ways, and the rule asks for whichever is there: the income itself between two
                // levels from 2024 — the cap stated where the base is, the floor in the section on
                // declaring and paying it, which is why both are read; in steps in 2023, the same
                // 6, 12 and 24 as every other income that year; and one level, 12 to 12, until 2022.
                'INDEPENDENT_BASE_WAGES' => [
                    'type' => 'JSON',
                    'law' => [
                        'within' => ['Titlul V Capitolul III Secţiunea a 5-a', 'Titlul V Capitolul III Secţiunea a 6-a'],
                        'question' => 'Cum se stabilește baza anuală de calcul al contribuției de asigurări sociale de sănătate pentru veniturile din activități independente prevăzute la art. 155 alin. (1) lit. b)? Legea o poate stabili în două feluri: (a) ca un interval — baza este venitul realizat, cu un nivel minim sub care nu poate coborî și un nivel maxim peste care nu poate urca; (b) în trepte — baza este un nivel fix, alt nivel pentru fiecare interval de venituri realizate. Răspunde în forma care corespunde legii în vigoare la data cerută. Dacă legea prevede un singur nivel pentru toate veniturile, răspunde ca interval, cu acel nivel la amândouă capetele.',
                        'answer' => 'wages_base',
                    ],
                ],

                // The other income — intellectual property, rent, dividends, interest, investments,
                // agriculture, other — is not counted itself: the base is the level it reaches. From
                // 2023 there are three of them; before, one level held for every income, and the
                // same rule reads that year as the one level it has.
                'OTHER_INCOME_BANDS_WAGES' => [
                    'type' => 'JSON',
                    'law' => [
                        'within' => ['Titlul V Capitolul III Secţiunea a 5-a', 'Titlul V Capitolul III Secţiunea a 6-a'],
                        'question' => 'Care sunt nivelurile bazei anuale de calcul al contribuției de asigurări sociale de sănătate pentru veniturile prevăzute la art. 155 alin. (1) lit. c)-h), după nivelul veniturilor realizate? Enumeră toate nivelurile; dacă legea prevede un singur nivel pentru toate veniturile, dă acel nivel.',
                        'answer' => 'wages_list',
                    ],
                ],

                // Whether the non-taxable investment income of art. 93 — public debt among it — is left
                // out of the income those levels are reached by (art. 170 alin. (5)).
                'PUBLIC_DEBT_EXCLUDED' => [
                    'type' => 'JSON',
                    'law' => [
                        'within' => ['Titlul V Capitolul III Secţiunea a 5-a'],
                        'question' => 'La încadrarea în plafonul anual al veniturilor prevăzute la art. 155 alin. (1) lit. c)-h), pentru stabilirea bazei de calcul al contribuției de asigurări sociale de sănătate, sunt lăsate în afara calculului (nu se iau în calcul) veniturile neimpozabile prevăzute la art. 93?',
                        'answer' => 'yes_no',
                    ],
                ],
            ],
        ],
    ],
];
