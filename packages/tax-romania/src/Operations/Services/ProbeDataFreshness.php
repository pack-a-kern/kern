<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Kern\Documents\Enums\DocumentStatus;
use Kern\Platform\Config\RequiredConfig;
use Kern\TaxRomania\Operations\DataTransferObjects\FreshnessItem;
use Throwable;

/**
 * "Is the data this installation depends on actually current?" — the question no queue metric
 * answers.
 *
 * Horizon can report an empty queue and every worker healthy while the exchange-rate table has not
 * gained a row in three weeks, because the job that should have added one was never dispatched.
 * These probes measure the data, not the machinery.
 *
 * Every table is read through the plain query builder, never through another module's Eloquent
 * models — the same crossing `Kern\Documents\Queries\ResolveTransactionImportState`
 * already makes into `financial_transactions` (`docs/AGENTS.md` §6). The one import from
 * another module is {@see DocumentStatus}, a backed enum: restating `'READY'`/`'REJECTED'` as
 * string literals here would silently stop matching the day that lifecycle gains a state.
 *
 * Each probe is wrapped individually, so an unreadable table degrades its own row instead of
 * failing the overview — this screen is most useful exactly when something is broken.
 */
final readonly class ProbeDataFreshness
{
    public function __construct(
        private RequiredConfig $required,
        private Migrator $migrator,
    ) {
    }

    /**
     * @return list<FreshnessItem>
     */
    public function __invoke(): array
    {
        return [
            $this->probe('fx_rates', fn (): FreshnessItem => $this->fxRates()),
            $this->probe('legislation', fn (): FreshnessItem => $this->legislation()),
            $this->probe('retrieval_index', fn (): FreshnessItem => $this->retrievalIndex()),
            $this->probe('stuck_documents', fn (): FreshnessItem => $this->stuckDocuments()),
            $this->probe('migrations', fn (): FreshnessItem => $this->migrations()),
        ];
    }

    /**
     * BNR reference rates. An empty table is `bad` rather than `warn`: with no rates at all, every
     * foreign-currency figure the tax engine would produce is unbacked, so this is a stop, not a
     * caution.
     */
    private function fxRates(): FreshnessItem
    {
        $total = DB::table('ext_fx_rates')->count();

        if ($total === 0) {
            return new FreshnessItem('fx_rates', 'bad', '0', null);
        }

        /** @var string|null $latest */
        $latest = DB::table('ext_fx_rates')->max('rate_date');

        if ($latest === null) {
            return new FreshnessItem('fx_rates', 'warn', (string) $total, null);
        }

        $ageDays = (int) CarbonImmutable::parse($latest)->startOfDay()
            ->diffInDays(CarbonImmutable::now()->startOfDay(), absolute: true);

        $maxAge = $this->required->positiveInt('operations.freshness.fx_max_age_days');

        return new FreshnessItem(
            key: 'fx_rates',
            state: $ageDays <= $maxAge ? 'ok' : 'warn',
            value: CarbonImmutable::parse($latest)->toDateString(),
            detail: (string) $total,
        );
    }

    /** Ingested legislation. Nothing ingested is `bad`: retrieval has nothing to cite. */
    private function legislation(): FreshnessItem
    {
        $documents = DB::table('ext_source_documents')->count();
        $sections = DB::table('ext_source_sections')->count();

        return new FreshnessItem(
            key: 'legislation',
            state: $documents === 0 ? 'bad' : ($sections === 0 ? 'warn' : 'ok'),
            value: (string) $documents,
            detail: (string) $sections,
        );
    }

    /**
     * The retrieval chunk index. Chunks that exist but carry no embedding are `warn`, not `bad`:
     * lexical search still works, and no embedding provider is configured yet in this installation
     * (`kern:legislation:reindex --embed` is documented as a no-op until one is).
     *
     * A chunk is embedded when its *text* has a vector, not when the chunk row does: one vector
     * serves every chunk repeating that text, which across 91 consolidations of one law is nearly
     * all of them. `ext_chunk_embeddings` exists only where pgvector does, so on a connection
     * without it the count is zero and this row reads `warn` — which is the truth, not a
     * workaround. Asking anyway would raise inside a wrapping transaction and, in Postgres, poison
     * every probe after this one; the per-probe `catch` cannot undo an aborted transaction.
     */
    private function retrievalIndex(): FreshnessItem
    {
        $chunks = DB::table('ext_source_section_chunks')->count();
        $embedded = DB::connection()->getDriverName() !== 'pgsql'
            ? 0
            : DB::table('ext_source_section_chunks as c')
                ->whereExists(function ($exists): void {
                    $exists->select(DB::raw('1'))
                        ->from('ext_chunk_embeddings as e')
                        ->whereColumn('e.text_hash', 'c.text_hash');
                })
                ->count();

        return new FreshnessItem(
            key: 'retrieval_index',
            state: $chunks === 0 ? 'bad' : ($embedded === 0 ? 'warn' : 'ok'),
            value: (string) $chunks,
            detail: (string) $embedded,
        );
    }

    /**
     * Documents left in a non-terminal state. This is the one figure Horizon cannot produce: the
     * job that should have advanced them has already finished — unsuccessfully, or by leaving the
     * document where a failed virus scan puts it (see `ClamdVirusScanner`) — so there is nothing
     * pending on any queue to count.
     */
    private function stuckDocuments(): FreshnessItem
    {
        $minutes = $this->required->positiveInt('operations.freshness.document_stuck_after_minutes');
        $cutoff = CarbonImmutable::now()->subMinutes($minutes);

        $terminal = [DocumentStatus::READY->value, DocumentStatus::REJECTED->value];

        $stuck = DB::table('documents')
            ->whereNull('deleted_at')
            ->whereNotIn('status', $terminal)
            ->where('updated_at', '<', $cutoff)
            ->count();

        return new FreshnessItem(
            key: 'stuck_documents',
            state: $stuck === 0 ? 'ok' : 'warn',
            value: (string) $stuck,
            detail: (string) $minutes,
        );
    }

    /**
     * Migrations present on disk but not applied — the state a container is in after a deploy that
     * forgot `migrate`, where the application runs and only the newest feature is broken.
     */
    private function migrations(): FreshnessItem
    {
        $ran = $this->migrator->getRepository()->getRan();
        $paths = array_values(array_unique([...$this->migrator->paths(), database_path('migrations')]));
        $files = $this->migrator->getMigrationFiles($paths);

        $pending = count(array_diff(array_keys($files), $ran));

        return new FreshnessItem(
            key: 'migrations',
            state: $pending === 0 ? 'ok' : 'bad',
            value: (string) $pending,
            detail: (string) count($files),
        );
    }

    /**
     * @param  callable(): FreshnessItem  $check
     */
    private function probe(string $key, callable $check): FreshnessItem
    {
        try {
            return $check();
        } catch (Throwable $exception) {
            // Same rule as `ProbeSystemHealth`: a driver's exception message can carry a DSN or a
            // host, and none of it is forwarded to an HTTP response (`BLUEPRINT.md` §4.10).
            report($exception);

            return new FreshnessItem($key, 'idle', null, null);
        }
    }
}
