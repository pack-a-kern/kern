<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge;

use App\Domain\TaxEngine\Contracts\FiscalParameterProvider;
use App\Domain\TaxEngine\Contracts\TaxRuleVersionProvider;
use App\Domain\TaxKnowledge\Fetching\AllowlistedHttpFetcher;
use App\Domain\TaxKnowledge\Providers\EloquentFiscalParameterProvider;
use App\Domain\TaxKnowledge\Providers\EloquentTaxRuleVersionProvider;
use App\Domain\TaxKnowledge\Retrieval\Embedding\EmbeddingProvider;
use App\Domain\TaxKnowledge\Retrieval\Embedding\HttpEmbeddingProvider;
use App\Domain\TaxKnowledge\Retrieval\Embedding\UnconfiguredEmbeddingProvider;
use App\Domain\TaxKnowledge\Storage\RawSourceObjectStore;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Also binds `App\Domain\TaxEngine`'s two ruleset/parameter ports
 * ({@see TaxRuleVersionProvider}, {@see FiscalParameterProvider}) to this module's Eloquent-
 * backed adapters ({@see EloquentTaxRuleVersionProvider}, {@see EloquentFiscalParameterProvider}).
 * This is the one place the dependency inversion in
 * `docs/adr/0010-tax-engine-ports-and-adapters.md` is wired up: TaxKnowledge depends on
 * TaxEngine's contracts/DTOs (a sanctioned crossing, `docs/AGENTS.md` §6), never the other way
 * round, so `deptrac.php` grants `TaxKnowledge -> TaxEngine`, not `TaxEngine -> TaxKnowledge`.
 * `bind()`, not `singleton()` — both adapters are stateless and cheap to construct, matching
 * `TaxRuleTemporalReader`/`FiscalParameterTemporalReader`'s prior lifecycle (constructed fresh
 * per resolution, never cached).
 *
 * Binds the two leaf classes that need scalar values out of `config/sources.php` — every other
 * class in this module (`OfficialSourceRegistry`, `OfficialSourceRepository`,
 * `SourceIngestionService`, ...) has only class-typed constructor dependencies the container
 * already knows how to resolve, so binding them explicitly here would add nothing
 * (`docs/CODE-QUALITY.md` §1 — no pointless indirection).
 *
 * `AllowlistedHttpFetcher` is bound with `bind()`, not `singleton()` — deliberately, the same
 * reasoning `docs/handoff/012-encrypted-object-store.md` decision #5 gives for
 * `EncryptedObjectStore`. A singleton would resolve `Illuminate\Http\Client\Factory` exactly once
 * and hold that reference for the container's lifetime; `Http::fake()` re-binds
 * `Factory::class` in the container (`Illuminate\Support\Facades\Facade::swap()`), so a singleton
 * `AllowlistedHttpFetcher` constructed before a *second*, later `Http::fake()` call in the same
 * test would keep talking to the first fake and silently miss the second — confirmed as a real,
 * not hypothetical, failure while writing this module's own anomaly-detection tests (two fetches
 * of different content in one test, the second `Http::fake()` call was ignored). `bind()` costs
 * nothing here — the constructor does no expensive work — and resolves the dependency fresh every
 * time, which is also simply more correct in any long-running worker process, not just in tests.
 */
final class TaxKnowledgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TaxRuleVersionProvider::class, EloquentTaxRuleVersionProvider::class);
        $this->app->bind(FiscalParameterProvider::class, EloquentFiscalParameterProvider::class);

        // Milestone 11 (docs/handoff/029-hybrid-retrieval.md): mirrors
        // App\Domain\Documents\DocumentsServiceProvider's ExternalDocumentAiProvider ->
        // NullDocumentAiProvider binding exactly. Singleton: UnconfiguredEmbeddingProvider is
        // stateless and holds no per-request resources, same reasoning as that precedent.
        // Off by default, and deliberately so: an installation whose `python-api` image predates
        // the embedding dependencies must keep failing loudly through
        // `UnconfiguredEmbeddingProvider` rather than start throwing connection errors deep inside
        // a chunk backfill because a config default changed under it.
        $this->app->singleton(
            EmbeddingProvider::class,
            static fn (Application $app): EmbeddingProvider => $app->make('config')->get('retrieval.embedding.enabled') === true
                ? $app->make(HttpEmbeddingProvider::class)
                : $app->make(UnconfiguredEmbeddingProvider::class),
        );

        $this->app->bind(AllowlistedHttpFetcher::class, static function (Application $app): AllowlistedHttpFetcher {
            $config = $app->make(ConfigRepository::class);
            $maxRedirects = $config->get('sources.fetch.max_redirects');
            $maxResponseBytes = $config->get('sources.fetch.max_response_bytes');
            $timeoutSeconds = $config->get('sources.fetch.timeout_seconds');

            return new AllowlistedHttpFetcher(
                $app->make(HttpFactory::class),
                $app->make(Config\OfficialSourceRegistry::class),
                is_int($maxRedirects) ? $maxRedirects : 5,
                is_int($maxResponseBytes) ? $maxResponseBytes : 10 * 1024 * 1024,
                is_int($timeoutSeconds) ? $timeoutSeconds : 15,
            );
        });

        $this->app->singleton(RawSourceObjectStore::class, static function (Application $app): RawSourceObjectStore {
            $config = $app->make(ConfigRepository::class);
            $disk = $config->get('sources.storage.disk');
            $prefix = $config->get('sources.storage.prefix');

            return new RawSourceObjectStore(
                $app->make(FilesystemFactory::class),
                is_string($disk) ? $disk : 's3',
                is_string($prefix) ? $prefix : 'sources',
            );
        });
    }
}
