<?php

declare(strict_types=1);

namespace App\Domain\Rental\Models;

use App\Domain\Rental\Enums\AccrualStatus;
use App\Domain\Subjects\Tenancy\ScopedToTaxSubject;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `rental_accruals` (`BLUEPRINT.md` §6.5). `rental_contract_version_id` is a justified addition
 * beyond the blueprint's literal column list — see this table's migration docblock for why it is
 * necessary for traceability across an amendment.
 *
 * `gross_minor` is already **the owning tax subject's share** of the contractual rent for this
 * period (`App\Domain\Rental\Services\OwnershipShareCalculator`), not the full rent — per
 * `BLUEPRINT.md` §33's co-ownership constraint. No encrypted fields: an amount and a date range carry
 * no PII on their own.
 *
 * `status` is deliberately **not** in `$fillable` — mass-assignment can never move a row to `PAID`
 * from outside this module's own code; only `App\Domain\Rental\Actions\RecordRentalPayment`
 * assigns it directly (`$accrual->status = ...`, bypassing the guard rail intentionally, the same
 * way `App\Domain\Documents\Models\DocumentExtraction::review()` is the one sanctioned exception
 * to its own identical rule). Because it is not fillable, a freshly `::create()`d instance would
 * otherwise hold `null` in memory for this attribute until re-fetched from the database (Eloquent
 * does not re-read a column's DB-level `default()` after `INSERT`) — `$attributes` below supplies
 * the identical default at the PHP object level, Laravel's documented "default attribute values"
 * mechanism, so a just-created instance and a freshly-queried one always agree.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $rental_contract_id
 * @property string $rental_contract_version_id
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property \Illuminate\Support\Carbon $due_date
 * @property int $gross_minor
 * @property AccrualStatus $status
 */
final class RentalAccrual extends Model
{
    use HasUuidPrimaryKey;
    use ScopedToTaxSubject;

    /** @var array<string, string> */
    protected $attributes = [
        'status' => 'PENDING',
    ];

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'rental_contract_id',
        'rental_contract_version_id',
        'period_start',
        'period_end',
        'due_date',
        'gross_minor',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'due_date' => 'date',
        'gross_minor' => 'integer',
        'status' => AccrualStatus::class,
    ];

    /** @return BelongsTo<RentalContract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class, 'rental_contract_id');
    }

    /** @return BelongsTo<RentalContractVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(RentalContractVersion::class, 'rental_contract_version_id');
    }

    /** @return HasMany<RentalPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(RentalPayment::class, 'accrual_id');
    }
}
