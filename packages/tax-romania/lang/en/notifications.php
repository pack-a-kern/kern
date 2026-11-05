<?php

declare(strict_types=1);

return [
    'types' => [
        'statement-import' => [
            'label' => 'Statement imports',
            'hint' => 'When a statement is ready to map or review, has been imported, or could not be read.',
        ],
        'deposit-maturity' => [
            'label' => 'Deposit maturity',
            'hint' => 'Before a term deposit matures — :days days ahead and on the day, at :time.',
        ],
    ],
    'imports' => [
        'READY_FOR_MAPPING' => ['title' => 'Statement read', 'body' => ':rows lines read. Check which column is which.'],
        'READY_FOR_REVIEW' => ['title' => 'Statement ready to review', 'body' => ':rows transactions, :suggested with a suggested category.'],
        'IMPORTED' => ['title' => 'Statement imported', 'body' => ':imported transactions added.'],
        'FAILED' => ['title' => 'Statement import failed', 'body' => 'The import stopped. Open it to see what happened.'],
    ],
    'deposits' => [
        'title_today' => 'A deposit matures today',
        'title_tomorrow' => 'A deposit matures tomorrow',
        'title_soon' => 'A deposit matures in :days days',
        'body' => ':bank: :amount on :date.',
        'no_bank' => 'Deposit',
    ],
];
