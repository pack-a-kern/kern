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

    'default_country' => env('SUBJECTS_DEFAULT_COUNTRY', 'RO'),

    'limits' => [
        'default' => (int) env('SUBJECTS_LIMIT_DEFAULT', 1),

        'per_role' => [
            'VIEWER' => (int) env('SUBJECTS_LIMIT_VIEWER', 1),
            'ACCOUNTANT' => (int) env('SUBJECTS_LIMIT_ACCOUNTANT', 50),
            'ADMIN' => (int) env('SUBJECTS_LIMIT_ADMIN', 100),
            'SUPER_ADMIN' => (int) env('SUBJECTS_LIMIT_SUPER_ADMIN', 1000),
        ],
    ],

];
