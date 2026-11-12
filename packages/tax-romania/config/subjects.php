<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | How many taxpayers one account may register
    |--------------------------------------------------------------------------
    |
    | Per platform role, because the answer genuinely differs: someone filing
    | for themselves needs one, an accountant filing for clients needs many.
    | `default` applies to an account with no role, or a role not listed here.
    |
    | These are ceilings on *owned* taxpayers. Access granted by someone else
    | does not count against them — see SubjectAllowance.
    |
    | No defaults: a value missing fails where it is read, naming the variable.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Country
    |--------------------------------------------------------------------------
    |
    | Every taxpayer this application registers is Romanian: the rules engine,
    | the declarations and the SPV integration are all RO-specific, so this is
    | not a field anyone gets to choose. It lives here rather than as a literal
    | in the registration action so there is one place to change if that ever
    | stops being true — and so the form can show what it will store.
    |
    */

    'default_country' => env('SUBJECTS_DEFAULT_COUNTRY'),

    'limits' => [
        'default' => env('SUBJECTS_LIMIT_DEFAULT'),

        'per_role' => [
            'VIEWER' => env('SUBJECTS_LIMIT_VIEWER'),
            'ACCOUNTANT' => env('SUBJECTS_LIMIT_ACCOUNTANT'),
            'ADMIN' => env('SUBJECTS_LIMIT_ADMIN'),
            'SUPER_ADMIN' => env('SUBJECTS_LIMIT_SUPER_ADMIN'),
        ],

        /*
         * How many of them may be in use at once, whatever the role: each one in use is named in the
         * taxpayer switcher on every page, and each name is decrypted under its own key.
         */
        'max_active' => env('SUBJECTS_MAX_ACTIVE'),
    ],

];
