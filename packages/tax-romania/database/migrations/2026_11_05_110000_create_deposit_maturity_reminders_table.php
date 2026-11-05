<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which maturity reminders have gone out: one row per deposit and per distance from its maturity
 * (`wallet.deposit.reminder_days`). The unique pair is what makes "7 days before" a thing sent once,
 * however often the daily task runs or is retried.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('deposit_maturity_reminders', function (Blueprint $table): void {
            $table->uuid('account_id');
            $table->foreign('account_id')->references('account_id')->on('deposit_terms')->cascadeOnDelete();
            $table->uuid('tax_subject_id');
            $table->unsignedSmallInteger('days_before');
            $table->timestampTz('sent_at');

            $table->primary(['account_id', 'days_before']);
            $table->index('tax_subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_maturity_reminders');
    }
};
