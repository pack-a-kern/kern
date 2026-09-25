<?php

declare(strict_types=1);

// Platform roles and permissions — the authorization catalogue this installation enforces.
//
// Kept in config, not in a seeder or a migration, so the set of permissions is reviewable in one
// place and changing it is a code review. The database rows are a projection of this file:
// `kern:access:sync` creates what is missing, and reports anything in the database that this file
// no longer declares (it never deletes — revoking a permission is a decision, not a side effect of
// an edit).
//
// A permission that no route or policy checks is worse than no permission at all: it appears on
// the Permissions screen as though it protects something. Every name below is accompanied by the
// route group that enforces it, and `AccessCatalogueTest` fails if a declared permission is
// enforced nowhere.
return [

    'permissions' => [

        'platform' => [
            // Enforced by the `permission:system.settings.view` middleware on GET /api/v1/settings*.
            'system.settings.view',
            // PUT /api/v1/settings/group/{group}, PUT/DELETE/POST /api/v1/settings/ai*.
            'system.settings.manage',
            // GET /api/v1/audit/*.
            'audit.view',
            // GET /api/v1/admin/users, and the role/permission mutations beside it.
            'users.view',
            'users.manage',
        ],

        'operations' => [
            // GET /api/v1/operations, /operations/runs, /operations/failed-jobs.
            'operations.view',
            // POST /operations/tasks/{task}/run, PUT /operations/tasks/{task},
            // POST|DELETE /operations/failed-jobs/{uuid}, PUT /operations/sources/{code}.
            // Deliberately not folded into `system.settings.manage`: "restart the importer" and
            // "change where taxpayer documents are sent for AI processing" are different levels of
            // trust, and one permission covering both would grant the second to everyone who needs
            // the first.
            'operations.run',
        ],

        'fiscal' => [
            // GET /api/v1/fiscal-parameters — the values a tax is computed from, and where each was read.
            'fiscal.parameters.view',
            // Approving or rejecting a value, and proposing a new year from the last: what every
            // taxpayer's tax is then computed from. BLUEPRINT.md §10.4 asks for two reviewers in
            // time; for now one person, named on the row and in the audit chain.
            'fiscal.parameters.approve',
        ],

        'documents' => [
            'documents.view',
            'documents.decrypt',
        ],

        'spv' => [
            'spv.connect',
        ],
    ],

    // Permissions that nothing checks yet. The Permissions screen renders these greyed with a
    // "not enforced" marker rather than a tick, so the matrix never overstates what is in force.
    'not_enforced' => [
        'documents.view',
        'documents.decrypt',
        'spv.connect',
    ],

    // Platform roles. `SUPER_ADMIN` deliberately holds everything, including permissions that are
    // not yet enforced, so that enforcing one later does not lock the operator out of the screen
    // that grants it.
    'roles' => [
        'SUPER_ADMIN' => ['*'],
        'ADMIN' => [
            'system.settings.view',
            'audit.view',
            'users.view',
            // Read the operations console, but not act on it. Starting an import or disabling an
            // official source is a SUPER_ADMIN action: both change what the installation does to
            // taxpayer data, and neither is reversible by re-clicking.
            'operations.view',
            'fiscal.parameters.view',
            'documents.view',
            'documents.decrypt',
            'spv.connect',
        ],
        'ACCOUNTANT' => [
            'fiscal.parameters.view',
            'documents.view',
            'documents.decrypt',
            'spv.connect',
        ],
        'VIEWER' => [
            'documents.view',
        ],
    ],

];
