<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Countries income can come from
    |--------------------------------------------------------------------------
    |
    | ISO 3166-1 alpha-2, keyed by the code that is stored on a transaction
    | split; the name is what a person picks from.
    |
    | Here rather than in the frontend for the same reason `config/reference/counties.php`
    | is: the server has to be able to refuse a code that does not exist, and a
    | list only the browser knows is a dropdown rather than a rule. The screen is
    | handed this same array as a page prop, so the form and the validator cannot
    | disagree about what is valid.
    |
    | This is a *vocabulary*, not a fiscal mapping. Which countries have treaties,
    | what relief they give and how foreign tax is credited are questions for a
    | versioned `TaxRuleVersion` resolved against approved sources — nothing here
    | decides anything about what is owed.
    |
    | Romania is first because it is the answer for almost every split; the rest
    | are alphabetical. Not the full ISO list: a dropdown of 249 entries is worse
    | than one of thirty, and adding a country is an edit to this file rather than
    | a code change.
    |
    */

    'all' => [
        'RO' => 'România',

        'AT' => 'Austria',
        'BE' => 'Belgia',
        'BG' => 'Bulgaria',
        'CA' => 'Canada',
        'CH' => 'Elveția',
        'CY' => 'Cipru',
        'CZ' => 'Cehia',
        'DE' => 'Germania',
        'DK' => 'Danemarca',
        'EE' => 'Estonia',
        'ES' => 'Spania',
        'FI' => 'Finlanda',
        'FR' => 'Franța',
        'GR' => 'Grecia',
        'HR' => 'Croația',
        'HU' => 'Ungaria',
        'IE' => 'Irlanda',
        'IL' => 'Israel',
        'IT' => 'Italia',
        'LT' => 'Lituania',
        'LU' => 'Luxemburg',
        'LV' => 'Letonia',
        'MD' => 'Republica Moldova',
        'MT' => 'Malta',
        'NL' => 'Țările de Jos',
        'NO' => 'Norvegia',
        'PL' => 'Polonia',
        'PT' => 'Portugalia',
        'SE' => 'Suedia',
        'SI' => 'Slovenia',
        'SK' => 'Slovacia',
        'TR' => 'Turcia',
        'UA' => 'Ucraina',
        'GB' => 'Regatul Unit',
        'US' => 'Statele Unite',
        'AE' => 'Emiratele Arabe Unite',
        'AU' => 'Australia',
        'SG' => 'Singapore',
    ],

];
