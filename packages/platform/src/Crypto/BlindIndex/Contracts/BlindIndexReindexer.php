<?php

declare(strict_types=1);

namespace Kern\Platform\Crypto\BlindIndex\Contracts;

use Kern\Platform\Crypto\BlindIndex\DataTransferObjects\ReindexOutcome;

/**
 * One blind-index column, and how to recompute it under the current key version.
 *
 * A blind index is `HMAC-SHA-256(key, normalized value)`, so rotating the key changes what every
 * value hashes to and every stored index has to be written again. There is no shortcut: a hash
 * cannot be turned back into its input, so the only way to produce the new one is to have the value
 * again — which is why every blind-indexed column in this codebase sits beside the ciphertext of
 * the value it indexes. ADR 0002 calls this "a real batch job, not a config flag flip"; an
 * implementation of this interface is one column's share of that job.
 *
 * Implementations are contributed by the package that owns the table, under
 * {@see \Kern\Platform\Crypto\BlindIndex\BlindIndexReindexers::TAG}, and the command that runs them
 * knows nothing about what any of them index.
 */
interface BlindIndexReindexer
{
    /**
     * What this covers, as `table.column`, for the report a run prints.
     */
    public function label(): string;

    /**
     * Recompute this column for every row that has a value to recompute from.
     *
     * Must be idempotent: a run over rows already on the current key writes nothing, so a job
     * interrupted halfway is finished by running it again. Must not rely on the tenant scope — this
     * is maintenance across every taxpayer, not a request acting for one.
     *
     * @param  int  $batch  rows held in memory at once.
     */
    public function reindex(int $batch): ReindexOutcome;
}
