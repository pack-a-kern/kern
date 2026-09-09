<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Subjects\Tenancy\ScopedToTaxSubject;
use App\Domain\Wallet\Enums\RecordSource;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `account_balance_snapshots` (`BLUEPRINT.md` §6.3). No encrypted fields, so — unlike
 * `FinancialAccount` — `ScopedToTaxSubject`'s auto-fill-on-create is sufficient on its own; no
 * `FillsSubjectForeignKeyFirst` is needed.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $account_id
 * @property int $balance_minor
 * @property \Illuminate\Support\Carbon $as_of
 * @property RecordSource $source
 * @property bool $is_verified
 */
final class AccountBalanceSnapshot extends Model
{
    use HasUuidPrimaryKey;
    use ScopedToTaxSubject;

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'account_id',
        'balance_minor',
        'as_of',
        'source',
        'is_verified',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'as_of' => 'datetime',
        'source' => RecordSource::class,
        'is_verified' => 'boolean',
    ];

    /** @return BelongsTo<FinancialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'account_id');
    }
}
