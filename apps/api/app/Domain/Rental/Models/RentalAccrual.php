<?php

declare(strict_types=1);

namespace App\Domain\Rental\Models;

use App\Domain\Subjects\Tenancy\ScopedToTaxSubject;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * Rent **owed**, not rent received: there is no paid/unpaid state. Rental income is set by the
 * contract "indiferent de momentul încasării chiriei" (Codul fiscal, art. 84), so whether the tenant
 * paid does not change any figure a tax return takes from here.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $rental_contract_id
 * @property string $rental_contract_version_id
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property \Illuminate\Support\Carbon $due_date
 * @property int $gross_minor
 */
final class RentalAccrual extends Model
{
    use HasUuidPrimaryKey;
    use ScopedToTaxSubject;

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
}
