<?php

declare(strict_types=1);

// Confirming an email address: the code that is mailed, and the answer when it is wrong.
return [
    'code_invalid' => 'The verification code is not valid.',
    'mail' => [
        'subject' => 'Confirm your email address',
        'intro' => 'Enter this code to confirm your email address:',
        'validity' => 'The code is valid for :minutes minutes.',
        'ignore' => 'If you did not ask for this, you can ignore this message.',
    ],
];
