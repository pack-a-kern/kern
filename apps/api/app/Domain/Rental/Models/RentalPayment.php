<?php

declare(strict_types=1);

namespace App\Domain\Rental\Models;

use App\Domain\Rental\Enums\TransactionMatchStatus;
use App\Domain\Subjects\Tenancy\ScopedToTaxSubject;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `rental_payments` (`BLUEPRINT.md` §6.5) — an actual, real-world payment, optionally linked to the
 * `rental_accruals` row it settles and to the `financial_transactions` (`Domain/Wallet`) row that
 * represents its bank-side movement. `transaction_id` is a plain foreign key at the database
 * level only (`docs/handoff/021-wallet.md`'s FX-provenance columns establish the identical
 * precedent: a migration may reference another module's table without the application ever
 * importing that module's Eloquent model, `docs/AGENTS.md` §6) — no `BelongsTo` relation to
 * `App\Domain\Wallet\Models\FinancialTransaction` exists here, deliberately.
 *
 * `transaction_match_status` starts `UNMATCHED` (the column's DB default); see
 * `App\Domain\Rental\Enums\TransactionMatchStatus` for the full state machine and
 * `App\Domain\Rental\Services\Matching\TransactionMatchCandidateFinder` for the matching
 * heuristic. No encrypted fields — an amount, a date, and a status carry no PII on their own.
 *
 * `transaction_id`/`transaction_match_status` are deliberately **not** in `$fillable` — mass
 * assignment can never fabricate a financial link. Only `App\Domain\Rental\Actions\
 * {ProposeRentalPaymentTransactionMatch,ConfirmRentalPaymentTransactionMatch,
 * RejectRentalPaymentTransactionMatch}` assign them directly, bypassing the guard rail
 * intentionally — the same pattern `App\Domain\Rental\Models\RentalAccrual.status` uses for the
 * identical reason. `$attributes` below gives a freshly `::create()`d instance the same
 * `UNMATCHED` default in memory that the database column default supplies on disk — see
 * `RentalAccrual`'s identical `$attributes` for why this is needed for a non-fillable column.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $rental_contract_id
 * @property string|null $accrual_id
 * @property string|null $transaction_id
 * @property TransactionMatchStatus $transaction_match_status
 * @property int $amount_minor
 * @property string $currency_code
 * @property \Illuminate\Support\Carbon $paid_at
 */
final class RentalPayment extends Model
{
    use HasUuidPrimaryKey;
    use ScopedToTaxSubject;

    /** @var array<string, string> */
    protected $attributes = [
        'transaction_match_status' => 'UNMATCHED',
    ];

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'rental_contract_id',
        'accrual_id',
        'amount_minor',
        'currency_code',
        'paid_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'transaction_match_status' => TransactionMatchStatus::class,
        'amount_minor' => 'integer',
        'paid_at' => 'date',
    ];

    /** @return BelongsTo<RentalContract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class, 'rental_contract_id');
    }

    /** @return BelongsTo<RentalAccrual, $this> */
    public function accrual(): BelongsTo
    {
        return $this->belongsTo(RentalAccrual::class, 'accrual_id');
    }
}
