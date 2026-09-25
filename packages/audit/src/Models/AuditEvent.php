<?php

declare(strict_types=1);

namespace Kern\Audit\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A row of the append-only, hash-chained audit trail (`BLUEPRINT.md` §4.8).
 *
 * The database itself refuses `UPDATE`/`DELETE` on this table (see the
 * `audit_events_deny_mutation` trigger created alongside this table's migration) — that is the
 * real tamper-evidence boundary, per `docs/AGENTS.md` §6b/CODE-QUALITY.md: "not merely by
 * convention". The overrides below are a second, cheaper line of defence: they turn an accidental
 * `$event->update(...)`/`->delete()` in application code into an immediate, obvious exception
 * instead of a round trip to the database to discover the same thing.
 *
 * Rows are written by {@see \Kern\Audit\Services\AuditChainService} via the query builder,
 * not `AuditEvent::create()` — the service must control `sequence`/`previous_hash`/`event_hash`
 * under an explicit row lock, which mass-assignment cannot express. This model exists for reading
 * (queries, tests, {@see \Kern\Audit\Services\ChainVerifier}).
 *
 * The columns are written out rather than inferred from the migrations. A package is read by static
 * analysis in applications that do not scan its migrations — that is what happened the moment this
 * became one — and a model whose types depend on the reader's configuration is a model with no
 * types.
 *
 * @property string $id
 * @property string $chain_key
 * @property int $sequence
 * @property string|null $subject_id
 * @property string|null $actor_id
 * @property string $event_type
 * @property string $resource_type
 * @property string $resource_id
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property string $payload_hash
 * @property string|null $previous_hash
 * @property string $event_hash
 * @property int $signature_key_version
 */
final class AuditEvent extends Model
{
    protected $table = 'audit_events';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'signature_key_version' => 'integer',
        ];
    }

    protected function performUpdate(Builder $query): never
    {
        throw new LogicException('audit_events is append-only: rows are never updated, see docs/adr for the hash-chain design.');
    }

    protected function performDeleteOnModel(): never
    {
        throw new LogicException('audit_events is append-only: rows are never deleted, see docs/adr for the hash-chain design.');
    }
}
