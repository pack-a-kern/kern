<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The current tip of one hash chain (`BLUEPRINT.md` §4.8's per-chain "last sequence, last hash"
 * pointer). Unlike {@see AuditEvent}, this row is legitimately mutated on every append — it is
 * the serialization point {@see \App\Domain\Audit\Services\AuditChainService} locks with
 * `SELECT ... FOR UPDATE` so concurrent appends to the *same* chain are ordered, while appends to
 * *different* chains never block each other.
 *
 * Read by {@see \App\Domain\Audit\Services\AuditChainService} and
 * {@see \App\Domain\Audit\Services\ChainVerifier} via the query builder (raw locking semantics),
 * not through this model's own query methods — it exists mainly for typed access in tests.
 */
final class AuditChainHead extends Model
{
    protected $table = 'audit_chain_heads';

    protected $primaryKey = 'chain_key';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_sequence' => 'integer',
        ];
    }
}
