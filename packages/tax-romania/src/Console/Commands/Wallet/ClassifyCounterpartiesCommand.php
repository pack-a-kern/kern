<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Console\Commands\Wallet;

use Illuminate\Console\Command;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\StatementImport\Jobs\ClassifyCounterpartyBatch;
use Kern\TaxRomania\StatementImport\Services\ClassifyKnownCounterparties;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Models\Counterparty;

/**
 * Queues the counterparties nobody has answered yet, for every taxpayer that has some.
 *
 * Scheduled — `OperationTask::COUNTERPARTIES_CLASSIFY`, every ten minutes — and runnable by hand from
 * the Tasks tab. It is what replaced asking during an import: a person uploading a statement no
 * longer waits for a model to read a hundred names, because those names are not a property of the
 * file and the answers are reused every month afterwards.
 *
 * ## Why it dispatches rather than asks
 *
 * It used to ask, and this command runs inside `RunOperationTask` on the `default` queue — sixty
 * seconds, and its configuration says plainly that model calls do not belong there. A batch is a
 * hundred names in one call of several minutes, so every scheduled run was killed before the call
 * returned and nothing was ever written. The calls are {@see ClassifyCounterpartyBatch} now, on the
 * queue built for them, and this only decides what to ask about.
 *
 * ## Who it says to act as
 *
 * Reading the worklist needs only the subject named. Writing the answer needs a bound tenant, so
 * each job carries the owner of that subject — a real principal who could have answered these
 * questions by hand, which is what the answers are proposals of. Everything written is marked `AI`,
 * so nothing reads as something the owner typed.
 *
 * A subject with nobody to act for is skipped rather than worked around. Inventing a principal to
 * get past that is the thing the gate exists to prevent.
 *
 * ## Why nothing is printed but counts
 *
 * The input is a list of a person's counterparties. A run that logged what it asked about would put
 * them in a file nobody encrypted.
 *
 * Safe to run repeatedly, and cheap when there is nothing to do: a taxpayer with no unanswered names
 * costs one `exists` query and no call.
 */
final class ClassifyCounterpartiesCommand extends Command
{
    protected $signature = 'kern:counterparties:classify {--subject= : One taxpayer, rather than all of them}';

    protected $description = 'Queue the counterparties nobody has answered, for a model to look at.';

    public function handle(ClassifyKnownCounterparties $classifier): int
    {
        $only = $this->option('subject');
        $only = is_string($only) && $only !== '' ? $only : null;

        $queued = 0;
        $taxpayers = 0;
        $unowned = 0;

        foreach ($this->subjectsWithWork($only) as $subjectId) {
            $owner = $this->ownerOf($subjectId);

            if ($owner === null) {
                $unowned++;

                continue;
            }

            $batches = $classifier->batches($subjectId);

            foreach ($batches as $batch) {
                ClassifyCounterpartyBatch::dispatch($subjectId, $owner, $batch);
                $queued++;
            }

            if ($batches !== []) {
                $taxpayers++;
            }
        }

        $this->info(sprintf('Queued %d batch(es) for %d taxpayer(s).', $queued, $taxpayers));

        if ($unowned > 0) {
            // Named rather than silent: a taxpayer nobody may act for looks exactly like a taxpayer
            // with nothing to do, and that is how a task stops working unnoticed.
            $this->warn(sprintf('%d taxpayer(s) skipped: nobody holds an unrevoked membership.', $unowned));
        }

        return self::SUCCESS;
    }

    /**
     * Somebody who may act for this taxpayer, preferring whoever owns it.
     *
     * The gate is asked separately, in `authorizeAndActivate`; this only chooses who to ask about. A
     * revoked membership is not a principal, which is why it is excluded here rather than left for
     * the gate to refuse one call later.
     */
    private function ownerOf(string $subjectId): ?string
    {
        $membership = SubjectMembership::query()
            ->where('subject_id', $subjectId)
            ->whereNull('revoked_at')
            ->orderByRaw('case when role = ? then 0 else 1 end', [MembershipRole::OWNER->value])
            ->first();

        return $membership === null ? null : (string) $membership->user_id;
    }

    /**
     * Taxpayers with at least one counterparty nobody has decided and nobody has asked about.
     *
     * @return list<string>
     */
    private function subjectsWithWork(?string $only): array
    {
        $pending = Counterparty::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereNull('category_code')
            ->whereNull('analysed_at')
            ->select('tax_subject_id')
            ->distinct();

        if ($only !== null) {
            $pending->where('tax_subject_id', $only);
        }

        /** @var list<string> $ids */
        $ids = $pending->pluck('tax_subject_id')->all();

        // Against the subjects that still exist: a deleted taxpayer's rows cascade, but a run
        // starting while one is being deleted should not ask about them.
        /** @var list<string> $existing */
        $existing = TaxSubject::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        return $existing;
    }
}
