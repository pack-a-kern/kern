<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Documents\Consent\DocumentAiConsentGate;
use App\Domain\Documents\ExternalAi\ExternalDocumentAiProvider;
use App\Domain\Documents\ExternalAi\NullDocumentAiProvider;
use App\Domain\Documents\ExternalAi\OpenAiCompatibleDocumentAiProvider;
use App\Domain\Documents\Extraction\HttpTextExtractionGateway;
use App\Domain\Documents\Extraction\PdfPageCounter;
use App\Domain\Documents\Extraction\TextExtractionGateway;
use App\Domain\Documents\FileSecurity\FilenameSanitizer;
use App\Domain\Documents\FileSecurity\FileSecurityValidator;
use App\Domain\Documents\Quarantine\QuarantineObjectStore;
use App\Domain\Documents\Scanning\ClamdVirusScanner;
use App\Domain\Documents\Scanning\VirusScanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\ObjectStore\EncryptedObjectStore;
use Kern\Platform\Settings\Services\ResolveAiProviderCredentials;

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
                $config->string('services.processing.base_url'),
                $config->integer('documents.text_extraction.timeout_seconds'),
                $config->string('services.processing.auth_token'),
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

        // Transient, like Kern\Platform\ObjectStore\ObjectStoreServiceProvider's own
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
        RateLimiter::for('documents-upload-per-subject', function (Request $request): Limit {
            $maxAttempts = config('documents.rate_limits.upload_per_subject.max_attempts');
            $decaySeconds = config('documents.rate_limits.upload_per_subject.decay_seconds');
            $subjectId = $request->route('subject');
            $key = is_string($subjectId) ? $subjectId : (string) $request->ip();

            return new Limit(
                $key,
                is_int($maxAttempts) ? $maxAttempts : 30,
                is_int($decaySeconds) ? $decaySeconds : 60,
            );
        });
    }
}
