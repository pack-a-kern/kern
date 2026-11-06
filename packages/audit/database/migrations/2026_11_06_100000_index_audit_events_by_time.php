<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The audit viewer pages newest first, by `occurred_at`, and anchors each read on the newest
 * `occurred_at` (`AuditEventQuery`). With no index on it, every page sorted the whole table — which
 * only grows.
 *
 * `occurred_at` alone for an administrator reading everything; `(subject_id, occurred_at)` for
 * someone reading their own taxpayers' events. The composite answers every lookup the
 * single-column `subject_id` index did, so that one goes.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->index('occurred_at');
            $table->index(['subject_id', 'occurred_at']);
            $table->dropIndex(['subject_id']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->index('subject_id');
            $table->dropIndex(['subject_id', 'occurred_at']);
            $table->dropIndex(['occurred_at']);
        });
    }
};
