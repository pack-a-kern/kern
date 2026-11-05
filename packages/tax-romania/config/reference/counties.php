<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Romanian counties
    |--------------------------------------------------------------------------
    |
    | Keyed by the code ANAF and the vehicle registers use, which is what is
    | stored; the name is what a person picks from.
    |
    | Here rather than in the frontend because the server is what has to refuse
    | a code that does not exist — a list only the browser knows is a dropdown,
    | not a rule. The taxpayers screen is handed this same array as a page prop,
    | so the form and the validator can never disagree about what is valid.
    |
    */

    'ro' => [
        'AB' => 'Alba',
        'AR' => 'Arad',
        'AG' => 'Argeș',
        'BC' => 'Bacău',
        'BH' => 'Bihor',
        'BN' => 'Bistrița-Năsăud',
        'BT' => 'Botoșani',
        'BV' => 'Brașov',
        'BR' => 'Brăila',
        'B' => 'București',
        'BZ' => 'Buzău',
        'CS' => 'Caraș-Severin',
        'CL' => 'Călărași',
        'CJ' => 'Cluj',
        'CT' => 'Constanța',
        'CV' => 'Covasna',
        'DB' => 'Dâmbovița',
        'DJ' => 'Dolj',
        'GL' => 'Galați',
        'GR' => 'Giurgiu',
        'GJ' => 'Gorj',
        'HR' => 'Harghita',
        'HD' => 'Hunedoara',
        'IL' => 'Ialomița',
        'IS' => 'Iași',
        'IF' => 'Ilfov',
        'MM' => 'Maramureș',
        'MH' => 'Mehedinți',
        'MS' => 'Mureș',
        'NT' => 'Neamț',
        'OT' => 'Olt',
        'PH' => 'Prahova',
        'SM' => 'Satu Mare',
        'SJ' => 'Sălaj',
        'SB' => 'Sibiu',
        'SV' => 'Suceava',
        'TR' => 'Teleorman',
        'TM' => 'Timiș',
        'TL' => 'Tulcea',
        'VS' => 'Vaslui',
        'VL' => 'Vâlcea',
        'VN' => 'Vrancea',
    ],

    /*
    |--------------------------------------------------------------------------
    | The CNP's own county codes
    |--------------------------------------------------------------------------
    |
    | A CNP records the county of registration as a number, 01-52, which is a
    | different scale from the letter codes above — and it has entries the
    | letters do not: 41-48 are Bucharest's sectors, which all map back to B,
    | and 70 names no county at all (null).
    |
    | Mapped to the letter code so a parsed CNP can pre-select the same county
    | the address field offers. Keys are strings because the leading zero is
    | part of the code. A number missing here is not a CNP county.
    |
    */

    'cnp' => [
        '01' => 'AB', '02' => 'AR', '03' => 'AG', '04' => 'BC', '05' => 'BH',
        '06' => 'BN', '07' => 'BT', '08' => 'BV', '09' => 'BR', '10' => 'BZ',
        '11' => 'CS', '12' => 'CJ', '13' => 'CT', '14' => 'CV', '15' => 'DB',
        '16' => 'DJ', '17' => 'GL', '18' => 'GJ', '19' => 'HR', '20' => 'HD',
        '21' => 'IL', '22' => 'IS', '23' => 'IF', '24' => 'MM', '25' => 'MH',
        '26' => 'MS', '27' => 'NT', '28' => 'OT', '29' => 'PH', '30' => 'SM',
        '31' => 'SJ', '32' => 'SB', '33' => 'SV', '34' => 'TR', '35' => 'TM',
        '36' => 'TL', '37' => 'VS', '38' => 'VL', '39' => 'VN',
        // Bucharest and its six sectors, and sectors 7 and 8, abolished in 1979 but still in the
        // CNPs issued while they existed.
        '40' => 'B', '41' => 'B', '42' => 'B', '43' => 'B', '44' => 'B', '45' => 'B', '46' => 'B',
        '47' => 'B', '48' => 'B',
        '51' => 'CL', '52' => 'GR',
        // Every CNP generated since 8 May 2024 carries 70, whatever the person's county: the number
        // names none, so the form asks for it.
        '70' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Localities a CNP names outright
    |--------------------------------------------------------------------------
    |
    | Only Bucharest has these: its six sectors have their own CNP county codes,
    | so the number identifies the locality and not merely the county. Every
    | other code names a county and leaves the town to be asked for.
    |
    */

    'cnp_localities' => [
        '41' => 'Sector 1',
        '42' => 'Sector 2',
        '43' => 'Sector 3',
        '44' => 'Sector 4',
        '45' => 'Sector 5',
        '46' => 'Sector 6',
    ],

];
