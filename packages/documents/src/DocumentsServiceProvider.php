<?php

declare(strict_types=1);

namespace Kern\Documents;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Kern\Documents\Consent\DocumentAiConsentGate;
use Kern\Documents\ExternalAi\ExternalDocumentAiProvider;
use Kern\Documents\ExternalAi\NullDocumentAiProvider;
use Kern\Documents\ExternalAi\OpenAiCompatibleDocumentAiProvider;
use Kern\Documents\Extraction\HttpTextExtractionGateway;
use Kern\Documents\Extraction\PdfPageCounter;
use Kern\Documents\Extraction\TextExtractionGateway;
use Kern\Documents\FileSecurity\FilenameSanitizer;
use Kern\Documents\FileSecurity\FileSecurityValidator;
use Kern\Documents\ObjectStore\EncryptedObjectStore;
use Kern\Documents\ObjectStore\ObjectStoreServiceProvider;
use Kern\Documents\Quarantine\QuarantineObjectStore;
use Kern\Documents\Scanning\ClamdVirusScanner;
use Kern\Documents\Scanning\ScannerBackedUploadScanner;
use Kern\Documents\Scanning\VirusScanner;
use Kern\Platform\Http\StackService;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Routing\ConfiguredRateLimit;
use Kern\Platform\Routing\PackageRoutes;
use Kern\Platform\Settings\Services\ResolveAiProviderCredentials;
use Kern\Platform\Uploads\Contracts\UploadScanner;

/**
 * Wires Milestone 7's document pipeline (`docs/handoff/022-document-pipeline.md`). See that
 * handoff note for the full lifecycle; the bindings below are the container's answer to "which
 * concrete class backs each seam."
 *
 * `ExternalDocumentAiProvider` binds to `NullDocumentAiProvider` — the one and only place a real
 * provider gets substituted once `docs/STATUS.md` blocked item #3 is resolved.
 */
final class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/documents.php', 'documents');
        $this->mergeConfigFrom(__DIR__ . '/../config/objectstore.php', 'objectstore');
        $this->mergeConfigFrom(__DIR__ . '/../config/ai/documents.php', 'ai.documents');

        // The encrypted object store is this package's: `ObjectKey` can only name a document's file,
        // and nothing outside here stores one. It was in `kern/platform` until somebody asked what
        // the base actually encrypted with it — the answer was nothing.
        $this->app->register(ObjectStoreServiceProvider::class);

        $this->app->singleton(FilenameSanitizer::class);

        $this->app->singleton(FileSecurityValidator::class, static function (Application $app): FileSecurityValidator {
            $config = $app->make(ConfigRepository::class);

            return new FileSecurityValidator(
                $app->make(FilenameSanitizer::class),
                $config->integer('documents.max_pdf_bytes'),
                $config->integer('documents.max_image_bytes'),
                $config->integer('documents.max_text_bytes'),
                $config->integer('documents.max_image_pixels'),
            );
        });

        /*
         * The platform's uploads scan through this package's clamd.
         *
         * `kern/platform` binds this port to a scanner that refuses everything, because it has no way to
         * reach one — it cannot import from here without making the dependency circular. This overrides
         * that default with the real thing, and is the only reason an avatar can be accepted at all.
         */
        $this->app->bind(UploadScanner::class, static function (Application $app): UploadScanner {
            return new ScannerBackedUploadScanner($app->make(VirusScanner::class));
        });

        $this->app->bind(VirusScanner::class, static function (Application $app): VirusScanner {
            $config = $app->make(ConfigRepository::class);

            return new ClamdVirusScanner(
                $config->string('documents.clamav.host'),
                $config->integer('documents.clamav.port'),
                $config->float('documents.clamav.connect_timeout_seconds'),
                $config->float('documents.clamav.stream_timeout_seconds'),
                $config->integer('documents.clamav.chunk_size'),
            );
        });

        // One object answers both contracts, because both are the same service asked a different
        // question — and binding them separately would open two HTTP clients to one endpoint.
        $this->app->singleton(HttpTextExtractionGateway::class, static function (Application $app): HttpTextExtractionGateway {
            $config = $app->make(ConfigRepository::class);

            return new HttpTextExtractionGateway(
                $app->make(HttpFactory::class),
                StackService::processing($config),
                $config->integer('documents.text_extraction.timeout_seconds'),
            );
        });

        $this->app->bind(TextExtractionGateway::class, HttpTextExtractionGateway::class);
        $this->app->bind(PdfPageCounter::class, HttpTextExtractionGateway::class);


        // A real provider only once one is actually configured. `ResolveAiProviderCredentials`
        // merges the stored settings with the env fallback, so this covers both — and until a base
        // URL and a model exist, the Null provider keeps the previous behaviour exactly: it throws,
        // `ProcessScannedDocument` catches, and nothing is sent anywhere.
        //
        // Bound as a factory rather than resolved once at boot: the settings are editable at
        // runtime, and a provider decided at container-build time would keep answering "not
        // configured" until the workers were restarted.
        $this->app->bind(ExternalDocumentAiProvider::class, static function (Application $app): ExternalDocumentAiProvider {
            $credentials = $app->make(ResolveAiProviderCredentials::class)();

            if (! $credentials->enabled || $credentials->baseUrl === null || $credentials->model === null) {
                return $app->make(NullDocumentAiProvider::class);
            }

            return $app->make(OpenAiCompatibleDocumentAiProvider::class);
        });
        $this->app->singleton(DocumentAiConsentGate::class);

        // Transient, like Kern\Documents\ObjectStore\ObjectStoreServiceProvider's own
        // EncryptedObjectStore binding: SubjectDekService is scoped per-request, so this must be
        // resolved fresh rather than pinned by a singleton (see that provider's docblock).
        $this->app->bind(QuarantineObjectStore::class, static function (Application $app): QuarantineObjectStore {
            $config = $app->make(ConfigRepository::class);
            $disk = Storage::disk($config->string('documents.quarantine_disk'));

            return new QuarantineObjectStore(new EncryptedObjectStore(
                $disk,
                $app->make(SubjectDekService::class),
                $config->integer('objectstore.chunk_size'),
            ));
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        PackageRoutes::loadForTenant(__DIR__ . '/../routes/api.php');

        $this->publishes([
            __DIR__ . '/../config/documents.php' => config_path('documents.php'),
            __DIR__ . '/../config/objectstore.php' => config_path('objectstore.php'),
            __DIR__ . '/../config/ai/documents.php' => config_path('ai/documents.php'),
        ], 'kern-documents-config');

        ConfiguredRateLimit::register(
            'documents-upload-per-subject',
            'documents.rate_limits.upload_per_subject',
            ConfiguredRateLimit::perTenant(),
        );
    }
}
