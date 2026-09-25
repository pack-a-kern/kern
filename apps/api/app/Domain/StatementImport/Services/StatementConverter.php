<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Services;

use App\Domain\Documents\Consent\DocumentAiConsentGate;
use App\Domain\Documents\FileSecurity\SniffedFileType;
use App\Domain\StatementImport\DataTransferObjects\ConvertedStatement;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Kern\Platform\Settings\Services\AiProviderCatalogue;
use Kern\Platform\Settings\Services\ResolveAiProviderCredentials;

/**
 * Turns a statement PDF into CSV through the provider's `docling` extension.
 *
 * The request is an ordinary chat completion with the file attached and a `docling` block beside the
 * messages, which is the gateway's own contract: it converts and answers without running a model, so
 * `model` is sent only because the schema requires one. The same transport as every other call — the
 * provider settings, the dialect's path and headers — so switching provider switches this too.
 *
 * ## Only when all three agree
 *
 * The endpoint says it can ({@see AiProviderCatalogue::supportsConversion()}), a provider is
 * configured and on, and the taxpayer has consented. Unlike the mapping call, the whole file leaves
 * the installation here, so the consent that covers sending a document is the one asked.
 *
 * ## Never logs content
 *
 * A reply that is not the shape expected logs its keys, never its values — a value here is somebody's
 * statement. A failure is a closed code, never the transport's message, which can embed the URI.
 */
final readonly class StatementConverter
{
    public function __construct(
        private HttpFactory $http,
        private AiProviderCatalogue $catalogue,
        private ResolveAiProviderCredentials $credentials,
        private DocumentAiConsentGate $consent,
        private ConfigRepository $config,
    ) {
    }

    public function isAvailable(string $subjectId): bool
    {
        if (! $this->catalogue->supportsConversion() || ! $this->consent->isConsented($subjectId)) {
            return false;
        }

        $credentials = ($this->credentials)();

        return $credentials->enabled && $credentials->baseUrl !== null && $credentials->model !== null;
    }

    /** Callers ask {@see isAvailable()} first; this sends. */
    public function convert(string $pdf): ConvertedStatement
    {
        $credentials = ($this->credentials)();

        if ($credentials->baseUrl === null || $credentials->model === null) {
            return ConvertedStatement::failed(ConvertedStatement::FAILED);
        }

        $dialect = $this->catalogue->dialectFor($credentials->provider);
        $format = $this->config->string('ai.statements.conversion.format');

        try {
            $response = $this->http
                ->timeout($this->config->integer('ai.statements.conversion.timeout_seconds'))
                ->withHeaders($credentials->apiKey === null
                    ? $dialect->unauthenticatedHeaders($this->catalogue->anthropicVersion())
                    : $dialect->authHeaders($credentials->apiKey, $this->catalogue->anthropicVersion()))
                ->post(rtrim($credentials->baseUrl, '/') . $dialect->chatPath(), [
                    'model' => $credentials->model,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [[
                            'type' => 'file',
                            'file' => [
                                'filename' => $this->config->string('ai.statements.conversion.filename'),
                                'file_data' => 'data:' . SniffedFileType::PDF->value . ';base64,' . base64_encode($pdf),
                            ],
                        ]],
                    ]],
                    'docling' => ['format' => $format],
                ]);
        } catch (ConnectionException) {
            return ConvertedStatement::failed(ConvertedStatement::FAILED);
        }

        if ($response->failed()) {
            return ConvertedStatement::failed(ConvertedStatement::FAILED);
        }

        $body = $response->json();
        $csv = is_array($body) ? ($body['content'] ?? null) : null;
        $answered = is_array($body) && is_string($body['format'] ?? null) ? $body['format'] : $format;

        // A table of another format would be read as CSV and come out as nonsense, so a reply that
        // says it is something else is refused rather than guessed at.
        if (! is_array($body) || ! is_string($csv) || trim($csv) === '' || strtolower($answered) !== strtolower($format)) {
            Log::info('statement_conversion_unrecognised_reply', ['keys' => is_array($body) ? array_keys($body) : [gettype($body)]]);

            return ConvertedStatement::failed(ConvertedStatement::EMPTY);
        }

        return ConvertedStatement::of(
            $csv,
            is_string($body['pipeline'] ?? null) ? $body['pipeline'] : null,
            is_numeric($body['ms'] ?? null) ? (int) $body['ms'] : null,
            is_string($body['warning'] ?? null) && $body['warning'] !== '' ? $body['warning'] : null,
        );
    }
}
