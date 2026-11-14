<?php

declare(strict_types=1);

namespace Kern\Investments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kern\Investments\Enums\Programme;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * A Ministry of Finance order read for its issues — see the migration that creates `ext_treasury_orders`.
 *
 * @property string $id
 * @property string $source_document_id
 * @property Programme $programme
 * @property int $issues_count
 * @property list<string>|null $skipped_issues the ISINs or codes of issues another order already wrote; null, read before this was kept
 * @property Carbon $read_at
 * @property string|null $parser_version the law parser its provisions were read under; null, read before this was kept
 */
final class TreasuryOrder extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ext_treasury_orders';

    /** @var list<string> */
    protected $fillable = ['source_document_id', 'programme', 'issues_count', 'skipped_issues', 'read_at', 'parser_version'];

    /** @var array<string, string> */
    protected $casts = [
        'programme' => Programme::class,
        'issues_count' => 'integer',
        'skipped_issues' => 'array',
        'read_at' => 'datetime',
    ];
}
