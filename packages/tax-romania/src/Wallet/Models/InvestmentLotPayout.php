<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Kern\Investments\Enums\PayoutKind;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Tenancy\ScopedToTenant;

/**
 * `investment_lot_payouts` — what one lot was owed of one payout, as it was credited; see the migration
 * that creates it. The gross and the withheld figure stay here, as a deposit's stay on its interest event;
 * the transaction holds the net, which is what arrived.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $lot_id
 * @property string $payout_id
 * @property PayoutKind $kind
 * @property \Illuminate\Support\Carbon $paid_on
 * @property string $currency
 * @property int $gross_minor
 * @property int|null $withheld_minor
 * @property int $net_minor
 * @property string|null $withholding_rate_version_id
 * @property string|null $transaction_id
 * @property string|null $fx_rate_decimal
 * @property \Illuminate\Support\Carbon|null $fx_rate_date
 * @property string|null $fx_source_code
 * @property string|null $fx_method
 * @property string|null $fx_content_sha256
 * @property string|null $fx_source_document_version_id
 * @property int|null $ron_equivalent_minor
 */
final class InvestmentLotPayout extends Model
{
    use HasUuidPrimaryKey;
    use ScopedToTenant;

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'lot_id',
        'payout_id',
        'kind',
        'paid_on',
        'currency',
        'gross_minor',
        'withheld_minor',
        'net_minor',
        'withholding_rate_version_id',
        'transaction_id',
        'fx_rate_decimal',
        'fx_rate_date',
        'fx_source_code',
        'fx_method',
        'fx_content_sha256',
        'fx_source_document_version_id',
        'ron_equivalent_minor',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'kind' => PayoutKind::class,
        'paid_on' => 'date',
        'gross_minor' => 'integer',
        'withheld_minor' => 'integer',
        'net_minor' => 'integer',
        'fx_rate_date' => 'date',
        'ron_equivalent_minor' => 'integer',
    ];
}
