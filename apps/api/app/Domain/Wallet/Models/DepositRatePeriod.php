<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Subjects\Tenancy\ScopedToTaxSubject;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `deposit_rate_periods` (`BLUEPRINT.md` §6.4). `annual_rate_decimal` is intentionally read/written
 * only through `App\Domain\Wallet\ValueObjects\Decimal::fromString()` — no Eloquent cast is
 * applied, for the identical reason `App\Domain\Fx\Models\FxRate` applies none to its own rate
 * column (see the migration's docblock: Laravel's built-in `decimal:N` cast round-trips through a
 * float internally).
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $deposit_id
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_to
 * @property string $annual_rate_decimal
 */
final class DepositRatePeriod extends Model
{
    use HasUuidPrimaryKey;
    use ScopedToTaxSubject;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'deposit_id',
        'valid_from',
        'valid_to',
        'annual_rate_decimal',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    /** @return BelongsTo<Deposit, $this> */
    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class, 'deposit_id');
    }
}
