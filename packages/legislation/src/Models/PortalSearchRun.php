<?php

declare(strict_types=1);

namespace Kern\Legislation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kern\Legislation\Enums\PortalSearchFailure;
use Kern\Legislation\Enums\PortalSearchStatus;
use Kern\Legislation\Legislatie\PortalAct;
use Kern\Legislation\Legislatie\PortalQuery;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * A search of legislatie.just.ro, read a page a job — see the migration that creates
 * `ext_portal_searches` and ADR 0024.
 *
 * @property string $id
 * @property string|null $user_id
 * @property array<array-key, mixed> $query
 * @property PortalSearchStatus $status
 * @property int $pages_read
 * @property array<array-key, mixed> $acts
 * @property array<array-key, mixed> $last_links
 * @property PortalSearchFailure|null $failure
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 */
final class PortalSearchRun extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ext_portal_searches';

    /** @var list<string> */
    protected $fillable = ['user_id', 'query', 'status', 'pages_read', 'acts', 'last_links', 'failure', 'finished_at'];

    /** @var array<string, string> */
    protected $casts = [
        'query' => 'array',
        'status' => PortalSearchStatus::class,
        'pages_read' => 'integer',
        'acts' => 'array',
        'last_links' => 'array',
        'failure' => PortalSearchFailure::class,
        'finished_at' => 'datetime',
    ];

    public function portalQuery(): PortalQuery
    {
        return PortalQuery::fromArray($this->query);
    }

    /** @return list<PortalAct> the acts kept so far, in the order the portal listed them */
    public function portalActs(): array
    {
        return array_values(array_map(
            static fn (mixed $kept): PortalAct => PortalAct::fromArray(is_array($kept) ? $kept : []),
            $this->acts,
        ));
    }

    /** @return list<string> */
    public function lastLinks(): array
    {
        return array_values(array_filter($this->last_links, is_string(...)));
    }
}
