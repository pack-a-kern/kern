<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops `linked_domain_type` and `linked_domain_id` from `transaction_splits`.
 *
 * They were a placeholder from the blueprint: an untyped reference from a split to a record in some
 * other module — a rental, a document — for tables that did not exist yet. Nothing ever wrote either
 * column; every split in every installation carries two nulls. A column nobody sets is still read,
 * and still suggests a link that is not there.
 *
 * The table itself stays. A split is how one payment is two things for tax — a repayment of 1 000
 * with 100 of interest on top is `LOAN_REPAYMENT` and `INTEREST`, taxed differently — even though no
 * screen offers more than one split per transaction yet.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('transaction_splits', function (Blueprint $table): void {
            $table->dropColumn(['linked_domain_type', 'linked_domain_id']);
        });
    }

    public function down(): void
    {
        Schema::table('transaction_splits', function (Blueprint $table): void {
            $table->string('linked_domain_type')->nullable()->after('category_code');
            $table->uuid('linked_domain_id')->nullable()->after('linked_domain_type');
        });
    }
};
