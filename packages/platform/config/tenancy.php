<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tenancy — defaults
|--------------------------------------------------------------------------
|
| Merged under the application's own `config/tenancy.php`, which is where the word belongs: a
| package that named the column `tax_subject_id` would know what a taxpayer is.
*/

return [

    // The column every scoped table carries. Read by the global scope that restricts reads and by
    // the hook that stamps writes; nothing else in the platform knows the tenant by any other name.
    'column' => 'tenant_id',

    // The table that column points at, or null where this installation has no tenants at all.
    'table' => null,

];
