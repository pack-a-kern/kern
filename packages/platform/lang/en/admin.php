<?php

declare(strict_types=1);

// Managing other people's accounts.
return [
    'erased_account' => 'This account has been erased and cannot be changed.',
    'erase' => [
        'self' => 'You cannot erase your own account.',
        'super_administrator' => 'A super administrator cannot be erased.',
        'already_erased' => 'This account has already been erased.',
    ],
    'roles' => [
        'own_last_role' => 'You cannot remove your own last platform role.',
        'last_super_administrator' => 'This is the last super administrator; grant the role to someone else first.',
    ],
    'status' => [
        'self' => 'You cannot suspend your own account.',
        'super_administrator_only' => 'Only a super administrator may change a super administrator\'s status.',
    ],
    'impersonation' => [
        'already' => 'Already acting as another user.',
        'self' => 'You are already signed in as yourself.',
        'super_administrator' => 'A super administrator cannot be impersonated.',
        'inactive' => 'Only an active account can be impersonated.',
        'not_impersonating' => 'This session is not impersonating anyone.',
    ],
];
