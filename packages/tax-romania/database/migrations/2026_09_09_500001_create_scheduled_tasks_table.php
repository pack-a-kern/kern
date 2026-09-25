<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether each schedulable task is currently active — the one thing about the schedule an
 * administrator may change at runtime.
 *
 * The schedule itself stays in `bootstrap/app.php`: *which* tasks exist is
 * `Kern\TaxRomania\Operations\Enums\OperationTask` (code, reviewable, allowlisted) and *when* they run
 * is `config/operations.php` (env-overridable). Only the on/off switch is data, and this table is
 * a row per switch.
 *
 * That split is the whole design. A table that named the command to execute would be remote code
 * execution behind an administration form; a column holding a free-text cron expression is how a
 * job stops running forever with no error raised anywhere. Neither exists here.
 *
 * A missing row means "enabled": `Kern\TaxRomania\Operations\Services\ScheduledTaskRegistry` treats
 * absence as the code-declared default, so adding a task to the enum does not require a data
 * migration before it will run.
 *
 * `key` is the primary key rather than a generated id, matching `settings.key`: the value is a
 * closed enum case, not free-form input, and is the stable row identity.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('scheduled_tasks', function (Blueprint $table): void {
            $table->string('key', 64)->primary();

            $table->boolean('enabled');

            $table->uuid('updated_by')->nullable();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampsTz(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_tasks');
    }
};
