<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The document types, renamed to the shorter list the extraction prompt can actually describe.
 *
 * `BANK_STATEMENT` becomes `ACCOUNT_STATEMENT` because a broker's and an exchange's statements have
 * the same shape; `RENTAL_CONTRACT` widens to `CONTRACT`; `ANAF_DECISION` widens to `TAX_DOCUMENT`,
 * which also covers a declarație, a notificare and an obligație de plată. `documents.type` is a
 * plain string column with no check constraint, so this is a data rename and nothing else.
 *
 * Reversible, because the old values are recoverable: each new value came from exactly one old one
 * in the data that exists, and `down()` restores that mapping. It cannot distinguish a `CONTRACT`
 * created after this migration from a renamed `RENTAL_CONTRACT`, which is the ordinary cost of
 * widening a category and not a reason to make the migration one-way.
 */
return new class () extends Migration {
    private const array RENAMES = [
        'BANK_STATEMENT' => 'ACCOUNT_STATEMENT',
        'RENTAL_CONTRACT' => 'CONTRACT',
        'ANAF_DECISION' => 'TAX_DOCUMENT',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $from => $to) {
            DB::table('documents')->where('type', $from)->update(['type' => $to]);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $from => $to) {
            DB::table('documents')->where('type', $to)->update(['type' => $from]);
        }
    }
};
