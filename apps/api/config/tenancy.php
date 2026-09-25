<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tenancy
|--------------------------------------------------------------------------
|
| What this installation calls its tenant. The mechanism — the global scope, the stamping on
| insert, the refusal when nothing is bound — is `kern/platform`, and who may act for whom is
| `Kern\TaxRomania\Subjects\Tenancy\MembershipTenantGate`. Only the word is here.
|
| An application with no such concept binds no gate and puts the column on no table: identity,
| encryption, the audit chain and documents all work as they are.
*/

return [

    'column' => 'tax_subject_id',

    // What that column points at. A package's migration adds the foreign key only where this table
    // already exists, so `kern/documents` can be installed without whatever owns the tenant.
    'table' => 'tax_subjects',

];
