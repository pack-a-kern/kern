<?php

declare(strict_types=1);

namespace Kern\Legislation;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Kern\Legislation\Console\Commands\ReindexLegislationCommand;
use Kern\Legislation\Console\Commands\SyncLegislationCommand;
use Kern\Legislation\Console\Commands\WatchLegislationCommand;
use Kern\Legislation\Dependents\DocumentDependents;
use Kern\Legislation\Dependents\SectionDependents;
use Kern\Legislation\Fetching\AllowlistedHttpFetcher;
use Kern\Legislation\Fetching\PageFetcher;
use Kern\Legislation\Fetching\SourcePageFetcher;
use Kern\Legislation\Queries\LawFlags;
use Kern\Legislation\Retrieval\Embedding\EmbeddingProvider;
use Kern\Legislation\Retrieval\Embedding\HttpEmbeddingProvider;
use Kern\Legislation\Retrieval\Embedding\UnconfiguredEmbeddingProvider;
use Kern\Legislation\Retrieval\Query\SearchSettings;
use Kern\Legislation\Storage\RawSourceObjectStore;
use Kern\Platform\Routing\ConfiguredRateLimit;
use Kern\Platform\Routing\PackageRoutes;
use RuntimeException;

/**
 * Reading the law: where a source may be fetched from, where its bytes are kept, and what embeds it.
 *
 * Binds only the classes that need scalar values out of configuration — every other class here has
 * class-typed constructor dependencies the container already resolves, so binding them would add
 * nothing (`docs/CODE-QUALITY.md` §1).
 *
 * `EmbeddingProvider` is `UnconfiguredEmbeddingProvider` when `ai.embeddings.enabled` is false,
 * and deliberately so: an installation whose `python-api` predates the embedding
 * dependencies must keep failing loudly rather than start throwing connection errors deep inside a
 * chunk backfill because a default changed under it.
 *
 * `AllowlistedHttpFetcher` is bound with `bind()`, not `singleton()`, for the reason
 * `docs/handoff/012-encrypted-object-store.md` decision #5 gives for `EncryptedObjectStore`. A
 * singleton resolves `Illuminate\Http\Client\Factory` once and holds it for the container's
 * lifetime; `Http::fake()` re-binds that class, so a fetcher constructed before a *second*, later
 * `Http::fake()` in the same test would keep talking to the first fake and silently miss the second.
 * Confirmed as a real failure while writing this module's anomaly-detection tests. `bind()` costs
 * nothing — the constructor does no work — and is simply more correct in a long-running worker.
 *
 * What is *not* here any more: the two `TaxEngine` ports this module used to satisfy. Reading a law
 * and computing a Romanian tax are different jobs, and the adapters went with the second one.
 */
final class LegislationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Whoever holds a reference to a paragraph declares it here, so a law is never deleted out
        // from under something that rests on it — and so this package needs to know about nothing.
        $this->app->singleton(SectionDependents::class);
        $this->app->singleton(DocumentDependents::class);
        $this->app->singleton(LawFlags::class);

        $this->mergeConfigFrom(__DIR__ . '/../config/legislation.php', 'legislation');
        $this->mergeConfigFrom(__DIR__ . '/../config/sources.php', 'sources');
        // The portal as the browser worker is told to read it — used only when the settings say so.
        $this->mergeConfigFrom(__DIR__ . '/../config/playbooks/legislatie.php', 'playbooks.legislatie');
        $this->mergeConfigFrom(__DIR__ . '/../config/ai/legislation.php', 'ai.legislation');
        $this->mergeConfigFrom(__DIR__ . '/../config/ai/embeddings.php', 'ai.embeddings');

        // Milestone 11 (docs/handoff/029-hybrid-retrieval.md): mirrors
        // Kern\Documents\DocumentsServiceProvider's ExternalDocumentAiProvider ->
        // NullDocumentAiProvider binding exactly. Singleton: UnconfiguredEmbeddingProvider is
        // stateless and holds no per-request resources, same reasoning as that precedent.
        // Read and checked once: a ranking value missing or out of range fails by name at the first search.
        $this->app->singleton(SearchSettings::class, static fn (Application $app): SearchSettings => SearchSettings::read($app->make(ConfigRepository::class)));

        // Chosen when installing, never assumed: a switch that is neither true nor false fails by name.
        $this->app->singleton(
            EmbeddingProvider::class,
            static fn (Application $app): EmbeddingProvider => match ($app->make('config')->get('ai.embeddings.enabled')) {
                true => $app->make(HttpEmbeddingProvider::class),
                false => $app->make(UnconfiguredEmbeddingProvider::class),
                default => throw new RuntimeException('RETRIEVAL_EMBEDDING_ENABLED must be true or false.'),
            },
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

        // Each source's pages the way its settings say — downloaded, or opened in the browser worker —
        // decided per page, so a setting changed on the screen reaches an import already running.
        $this->app->bind(PageFetcher::class, SourcePageFetcher::class);

        $this->app->singleton(RawSourceObjectStore::class, static function (Application $app): RawSourceObjectStore {
            $config = $app->make(ConfigRepository::class);

            return new RawSourceObjectStore(
                $app->make(FilesystemFactory::class),
                $config->string('sources.storage.disk'),
                $config->string('sources.storage.prefix'),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        // The sentences this package's HTTP layer answers with. `legislation::laws.*`.
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'legislation');

        PackageRoutes::load(__DIR__ . '/../routes/api.php');

        ConfiguredRateLimit::register('legislation-search-per-user', 'legislation.rate_limits.search_per_user', ConfiguredRateLimit::perUser());

        $this->publishes([
            __DIR__ . '/../config/legislation.php' => config_path('legislation.php'),
            __DIR__ . '/../config/sources.php' => config_path('sources.php'),
            __DIR__ . '/../config/ai/legislation.php' => config_path('ai/legislation.php'),
            __DIR__ . '/../config/ai/embeddings.php' => config_path('ai/embeddings.php'),
        ], 'kern-legislation-config');

        if ($this->app->runningInConsole()) {
            $this->commands([SyncLegislationCommand::class, ReindexLegislationCommand::class, WatchLegislationCommand::class]);
        }
    }
}
