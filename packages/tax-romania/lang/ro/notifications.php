<?php

declare(strict_types=1);

return [
    'types' => [
        'statement-import' => [
            'label' => 'Importuri de extrase',
            'hint' => 'Când un extras e gata de mapat sau de verificat, a fost importat sau nu a putut fi citit.',
        ],
        'deposit-maturity' => [
            'label' => 'Scadența depozitelor',
            'hint' => 'Înainte ca un depozit la termen să ajungă la scadență — cu :days zile înainte și în ziua respectivă, la :time.',
        ],
        'holding-payout' => [
            'label' => 'Cupoane și scadența obligațiunilor',
            'hint' => 'Înainte ca o obligațiune deținută să plătească un cupon sau să fie rambursată — cu :days zile înainte și în ziua respectivă, la :time.',
        ],
        'issue-on-offer' => [
            'label' => 'Emisiuni noi de titluri de stat',
            'hint' => 'Când o nouă emisiune Fidelis sau Tezaur poate fi subscrisă.',
        ],
    ],
    'imports' => [
        'READY_FOR_MAPPING' => ['title' => 'Extras citit', 'body' => ':rows linii citite. Verifică coloanele.'],
        'READY_FOR_REVIEW' => ['title' => 'Extras gata de verificat', 'body' => ':rows tranzacții, :suggested cu o categorie sugerată.'],
        'IMPORTED' => ['title' => 'Extras importat', 'body' => ':imported tranzacții adăugate.'],
        'FAILED' => ['title' => 'Importul extrasului a eșuat', 'body' => 'Importul s-a oprit. Deschide-l ca să vezi de ce.'],
    ],
    'deposits' => [
        'title_today' => 'Un depozit ajunge azi la scadență',
        'title_tomorrow' => 'Un depozit ajunge mâine la scadență',
        'title_soon' => 'Un depozit ajunge la scadență în :days zile',
        'body' => ':bank: :amount pe :date.',
        'no_bank' => 'Depozit',
    ],
    'holdings' => [
        'coupon' => [
            'title_today' => 'Un cupon se plătește azi',
            'title_tomorrow' => 'Un cupon se plătește mâine',
            'title_soon' => 'Un cupon se plătește în :days zile',
        ],
        'principal' => [
            'title_today' => 'O obligațiune se rambursează azi',
            'title_tomorrow' => 'O obligațiune se rambursează mâine',
            'title_soon' => 'O obligațiune se rambursează în :days zile',
        ],
        'body' => ':instrument, pe :date.',
    ],
    'issues' => [
        'title' => 'În subscriere: :name',
        'body' => ':rate% pe an, în :currency, cu scadența pe :date.',
    ],
];
