<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tenancy
|--------------------------------------------------------------------------
|
| Who a row belongs to, and what that owner is called here.
|
| The mechanism is the same in any application: every row belongs to an owner, every read is
| restricted to the active one, every insert is stamped with it, and stepping outside takes a
| named, greppable escape (`Model::withoutGlobalScope(TenantScope::class)`). What differs from one
| application to the next is only the vocabulary — a taxpayer here, a workspace or a customer
| elsewhere — so the vocabulary is configuration and the mechanism is `App\Support\Tenancy`.
|
| `model` null means this installation has no tenants: nothing is scoped, nothing is stamped, and
| the rest of the platform — identity, the audit chain, encryption, documents — works unchanged.
*/

return [

    'model' => App\Domain\Subjects\Models\TaxSubject::class,

    // The table the owner column points at. Read by the schema helper that adds the foreign key
    // only where this table exists, so a module can be installed without the one that owns it.
    'table' => 'tax_subjects',

    'column' => 'tax_subject_id',
];
