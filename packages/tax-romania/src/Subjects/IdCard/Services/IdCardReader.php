<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\IdCard\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use JsonException;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Services\AiProviderCatalogue;
use Kern\Platform\Settings\Services\ResolveAiProviderCredentials;
use Kern\Platform\Settings\Services\ResolveTaskModel;
use Kern\TaxRomania\Subjects\IdCard\DataTransferObjects\IdCardReading;
use Kern\TaxRomania\Subjects\IdCard\DataTransferObjects\IdCardReadResult;

/**
 * Puts one identity card to the configured vision model and returns what it read — the direct
 * provider call `StatementAiClient`/`EnhanceImageController` already make (ADR 0021), never the
 * stubbed document path, because nothing about the card is stored (ADR 0028).
 *
 * It takes raw bytes, not a stored document: the card lives only in the encrypted scratch store
 * between upload and here (ADR 0028). It never logs the image or the reply — both are a person's
 * identity card — and it never forwards a transport error's text, which can embed the request URL
 * (BLUEPRINT §4.10). Every failure collapses to a coarse reason the form turns into "type it by
 * hand".
 */
final readonly class IdCardReader
{
    public function __construct(
        private ResolveAiProviderCredentials $credentials,
        private AiProviderCatalogue $catalogue,
        private HttpFactory $http,
        private ConfigRepository $config,
        private ResolveTaskModel $resolveTaskModel,
    ) {
    }

    public function read(string $imageBytes, string $mimeType): IdCardReadResult
    {
        $credentials = ($this->credentials)();
        $model = ($this->resolveTaskModel)(SettingKey::AI_ID_CARD_MODEL) ?? $credentials->model;

        if (! $credentials->enabled || $credentials->baseUrl === null || $model === null) {
            return IdCardReadResult::failed(IdCardReadResult::UNAVAILABLE);
        }

        $dialect = $this->catalogue->dialectFor($credentials->provider);

        $body = array_merge(
            $dialect->extractionBody(
                model: $model,
                systemPrompt: $this->config->string('ai.idcard.read.system'),
                instruction: $this->config->string('ai.idcard.read.instruction'),
                // The card is the image, not text: there is no document text to send.
                documentText: null,
                images: [['mime_type' => $mimeType, 'base64' => base64_encode($imageBytes)]],
                maxTokens: $this->config->integer('ai.idcard.read.max_tokens'),
            ),
            $dialect->jsonFormat($this->schema()),
        );

        $headers = $credentials->apiKey === null
            ? $dialect->unauthenticatedHeaders($this->catalogue->anthropicVersion())
            : $dialect->authHeaders($credentials->apiKey, $this->catalogue->anthropicVersion());

        try {
            $response = $this->http
                ->timeout($this->config->integer('ai.idcard.read.timeout_seconds'))
                ->withHeaders($headers)
                ->post(rtrim($credentials->baseUrl, '/') . $dialect->chatPath(), $body);
        } catch (ConnectionException) {
            return IdCardReadResult::failed(IdCardReadResult::REFUSED);
        }

        if ($response->failed()) {
            return IdCardReadResult::failed(IdCardReadResult::REFUSED);
        }

        /** @var array<string, mixed>|null $json */
        $json = $response->json();
        $reply = data_get($json, $dialect->replyTextPath());

        if (! is_string($reply) || trim($reply) === '') {
            return IdCardReadResult::failed(IdCardReadResult::UNREADABLE);
        }

        $decoded = $this->decode($reply);

        return $decoded === null
            ? IdCardReadResult::failed(IdCardReadResult::UNREADABLE)
            : IdCardReadResult::of(IdCardReading::fromModel($decoded));
    }

    /**
     * The JSON object the reply must be. Fields are strings or null; the box is four fractions. The
     * shape, not the values — the values are checked, clamped and ultimately confirmed downstream.
     *
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $fraction = ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'cnp' => $nullableString,
                'last_name' => $nullableString,
                'first_name' => $nullableString,
                'address' => $nullableString,
                'series' => $nullableString,
                'number' => $nullableString,
                'valid_until' => $nullableString,
                'face_box' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => false,
                    'properties' => [
                        'x' => $fraction,
                        'y' => $fraction,
                        'w' => $fraction,
                        'h' => $fraction,
                    ],
                ],
            ],
        ];
    }

    /**
     * The reply text to a JSON object, or null. Models still fence JSON in markdown however firmly
     * they are told not to, so one fence is stripped before decoding.
     *
     * @return array<string, mixed>|null
     */
    private function decode(string $reply): ?array
    {
        $trimmed = trim($reply);
        $trimmed = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $trimmed) ?? $trimmed);

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
