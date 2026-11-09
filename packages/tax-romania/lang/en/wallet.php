<?php

declare(strict_types=1);

// The wallet: accounts, transactions and the partners money moves between.
return [
    'counterparty_not_found' => 'One of the counterparties could not be found.',
    // The name of the money account a holding's payments arrive in, one per place held and currency.
    'holding_account' => ':place · :currency',
    'held_at' => ['TREASURY' => 'Treasury', 'BANK' => 'Bank', 'BROKER' => 'Broker'],
    'source_missing' => 'The selected source does not exist.',
    'deposit_csv' => [
        'not_utf8' => 'The file is not UTF-8. Save it from the spreadsheet as "CSV UTF-8".',
        'empty' => 'The file has no deposits under its header line.',
        'too_many' => 'The file has more than :max deposits. Split it into smaller files.',
        'no_such_column' => 'The file has no column with this name.',
    ],
];
