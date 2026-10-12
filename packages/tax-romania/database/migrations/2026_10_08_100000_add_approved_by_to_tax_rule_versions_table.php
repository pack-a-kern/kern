<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who approved a rule version.
 *
 * The table recorded `approved_at` and not `approved_by`, so it could say a version had been approved and never
 * by whom — and `ForbidsAgentApproval`, which this model already uses, exists precisely to make that impossible:
 * "the database refuses an `APPROVED` row that names no one." It could not have named one. Which is also why the
 * model has no `approveByPerson()` while `FiscalParameterVersion` does, and why nothing has ever approved a rule.
 *
 * No foreign key, matching `fiscal_parameter_versions.approved_by`: a person who has since been erased must not
 * take a fiscal decision's provenance with them, and `users` is a table a right-to-erasure request empties.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('tax_rule_versions', function (Blueprint $table): void {
            $table->uuid('approved_by')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tax_rule_versions', function (Blueprint $table): void {
            $table->dropColumn('approved_by');
        });
    }
};
