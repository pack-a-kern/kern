<?php

declare(strict_types=1);

namespace App\Console\Commands\Audit;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kern\Audit\Services\ChainVerifier;

/**
 * Re-verifies every audit hash chain (`BLUEPRINT.md` §4.8) — walking each chain and recomputing
 * every `event_hash` from the canonical event and its predecessor.
 *
 * A CLI entry point for what `GET /api/v1/audit/summary` already does for the dashboard, with one
 * difference that matters: the endpoint bounds itself to 25 chains so a page load stays fast, while
 * this command verifies *all* of them. Full verification is an operational task with a duration,
 * which is why it belongs on a queue and in the run ledger
 * (`App\Domain\Operations\Enums\OperationTask::AUDIT_VERIFY_CHAINS`) rather than in a request.
 *
 * Exit code 1 on a broken chain is the whole contract: the run ledger records failure from the exit
 * code, so tamper evidence reaches the operations screen without this command needing to know that
 * screen exists.
 */
final class VerifyAuditChainsCommand extends Command
{
    /** @var string */
    protected $signature = 'kern:audit:verify
        {--chain= : Verify only this chain key (default: every chain)}';

    /** @var string */
    protected $description = 'Recompute every audit chain hash and report the first chain that fails (BLUEPRINT.md §4.8).';

    public function handle(ChainVerifier $verifier): int
    {
        $chainKeys = $this->chainKeys();

        if ($chainKeys === []) {
            $this->components->info('No audit chains to verify.');

            return self::SUCCESS;
        }

        $verified = 0;

        foreach ($chainKeys as $chainKey) {
            $result = $verifier->verify($chainKey);
            $verified += $result->verifiedCount;

            if (! $result->ok) {
                $reason = $result->reason;

                $this->components->error(sprintf(
                    'Chain %s failed at sequence %s (%s). %d event(s) verified before the failure.',
                    $result->chainKey,
                    $result->brokenAtSequence === null ? 'unknown' : (string) $result->brokenAtSequence,
                    $reason === null ? 'unknown reason' : $reason->value,
                    $verified,
                ));

                return self::FAILURE;
            }
        }

        $this->components->info(sprintf(
            '%d chain(s) verified, %d event(s) recomputed, no tampering detected.',
            count($chainKeys),
            $verified,
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function chainKeys(): array
    {
        $only = $this->option('chain');

        if (is_string($only) && $only !== '') {
            return [$only];
        }

        /** @var list<string> $keys */
        $keys = DB::table('audit_events')->distinct()->orderBy('chain_key')->pluck('chain_key')->all();

        return $keys;
    }
}
