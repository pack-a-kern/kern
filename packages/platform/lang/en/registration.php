<?php

declare(strict_types=1);

// The mail sent to an address that already has an account when someone tries to register it.
return [
    'attempt' => [
        'subject' => 'Someone tried to create an account with your address',
        'tried' => 'Someone tried to create a new Kern account with this email address. It already has an account, so nothing was created and nothing was changed.',
        'if_you' => 'If it was you, there is nothing to do: sign in with the account you already have.',
        'if_not' => 'If it was not you, you can ignore this message. Your account and your password are as they were.',
    ],
];
