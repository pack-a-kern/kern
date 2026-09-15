<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seventeen transaction categories become fourteen (`config/tax_categories.php`).
 *
 * - `SALARY` and `PENSION` become `SALARY_PENSION`: both are taxed by whoever pays them, and nothing
 *   a person declares tells the two apart.
 * - `LOAN_RECEIVED` and `LOAN_REPAID` become `LOAN` and `LOAN_REPAYMENT`: which side of the loan a
 *   person is on is already the sign of the amount, so lending money now has a category too.
 * - `GIFT`, `REIMBURSEMENT` and `OTHER_NOT_INCOME` become `NOT_INCOME`: none has a tax effect, and
 *   three names for "not income" made a person choose between answers that change nothing.
 */
return new class () extends Migration {
    private const RENAMED = [
        'SALARY' => 'SALARY_PENSION',
        'PENSION' => 'SALARY_PENSION',
        'LOAN_RECEIVED' => 'LOAN',
        'LOAN_REPAID' => 'LOAN_REPAYMENT',
        'GIFT' => 'NOT_INCOME',
        'REIMBURSEMENT' => 'NOT_INCOME',
        'OTHER_NOT_INCOME' => 'NOT_INCOME',
    ];

    public function up(): void
    {
        foreach (self::RENAMED as $from => $to) {
            DB::table('transaction_splits')->where('category_code', $from)->update(['category_code' => $to]);
        }
    }

    /**
     * Back to one old name for each new one. The merged codes cannot be split again — a `NOT_INCOME`
     * row does not say whether it was a gift or a refund — so each returns to the widest of them.
     */
    public function down(): void
    {
        $restored = [
            'SALARY_PENSION' => 'SALARY',
            'LOAN' => 'LOAN_RECEIVED',
            'LOAN_REPAYMENT' => 'LOAN_REPAID',
            'NOT_INCOME' => 'OTHER_NOT_INCOME',
        ];

        foreach ($restored as $from => $to) {
            DB::table('transaction_splits')->where('category_code', $from)->update(['category_code' => $to]);
        }
    }
};
