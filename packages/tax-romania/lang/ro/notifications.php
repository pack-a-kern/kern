<?php

declare(strict_types=1);

return [
    'types' => [
        'statement-imports' => [
            'label' => 'Importuri de extrase',
            'hint' => 'Când un extras e gata de mapat sau de verificat, a fost importat sau nu a putut fi citit.',
        ],
        'deposit-maturity' => [
            'label' => 'Scadența depozitelor',
            'hint' => 'Înainte ca un depozit la termen să ajungă la scadență — cu :days zile înainte și în ziua respectivă, la :time.',
        ],
    ],
    'imports' => [
        'READY_FOR_MAPPING' => ['title' => 'Extras citit', 'body' => 'Alege ce reprezintă fiecare coloană ca să continui.'],
        'READY_FOR_REVIEW' => ['title' => 'Extras gata de verificat', 'body' => ':rows rânduri citite, :suggested cu propunere.'],
        'IMPORTED' => ['title' => 'Extras importat', 'body' => ':imported din :rows rânduri importate.'],
        'FAILED' => ['title' => 'Extrasul nu a putut fi importat', 'body' => 'Deschide-l ca să vezi de ce.'],
    ],
    'deposits' => [
        'title_today' => 'Un depozit ajunge azi la scadență',
        'title_soon' => 'Un depozit ajunge la scadență în :days zile',
        'title_tomorrow' => 'Un depozit ajunge mâine la scadență',
        'body' => ':bank: :amount pe :date.',
        'no_bank' => 'Depozit',
    ],
];
