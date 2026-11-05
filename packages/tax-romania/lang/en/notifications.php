<?php

declare(strict_types=1);

return [
    'types' => [
        'statement-imports' => [
            'label' => 'Statement imports',
            'hint' => 'When a statement is ready to map or review, has been imported, or could not be read.',
        ],
        'deposit-maturity' => [
            'label' => 'Deposit maturity',
            'hint' => 'Before a term deposit matures — :days days ahead and on the day, at :time.',
        ],
    ],
    'imports' => [
        'READY_FOR_MAPPING' => ['title' => 'Statement read', 'body' => 'Choose which column is which to continue.'],
        'READY_FOR_REVIEW' => ['title' => 'Statement ready to review', 'body' => ':rows rows read, :suggested with a proposal.'],
        'IMPORTED' => ['title' => 'Statement imported', 'body' => ':imported of :rows rows imported.'],
        'FAILED' => ['title' => 'Statement could not be imported', 'body' => 'Open it to see why.'],
    ],
    'deposits' => [
        'title_today' => 'A deposit matures today',
        'title_soon' => 'A deposit matures in :days days',
        'title_tomorrow' => 'A deposit matures tomorrow',
        'body' => ':bank: :amount on :date.',
        'no_bank' => 'Deposit',
    ],
];
