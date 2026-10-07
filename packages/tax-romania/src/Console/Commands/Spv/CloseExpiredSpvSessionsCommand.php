<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Console\Commands\Spv;

use Illuminate\Console\Command;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;
use Kern\TaxRomania\Spv\Models\SpvSession;

/**
 * Closes the session rows whose browser the worker has already thrown away.
 *
 * A sitting lives in a running Chromium that the worker expires at its own TTL, silently. Nothing on this
 * side noticed, so a row kept saying AUTHENTICATED with `destroyed_at` null for a browser gone an hour
 * earlier — and three separate readers filtered on `expires_at > now()` to avoid believing it. A filter
 * repeated in three places to work around a record nobody closes is the record's problem.
 *
 * No call to the worker. The expiry written on the row is the one the worker itself reported, so a row
 * past it describes a browser the worker has already swept; asking it to destroy a session it has
 * forgotten would be a round trip to be told nothing.
 */
final class CloseExpiredSpvSessionsCommand extends Command
{
    protected $signature = 'kern:spv:close-expired-sessions';

    protected $description = 'Mark SPV sessions destroyed once the browser worker has expired their browser';

    public function handle(): int
    {
        $closed = SpvSession::query()
            ->withoutGlobalScopes()
            ->whereNull('destroyed_at')
            ->where('expires_at', '<=', now('UTC'))
            ->update([
                'destroyed_at' => now('UTC'),
                'state' => SpvSessionState::FAILED,
            ]);

        $this->info($closed === 0 ? 'No expired sessions.' : "Closed {$closed} expired session(s).");

        return self::SUCCESS;
    }
}
