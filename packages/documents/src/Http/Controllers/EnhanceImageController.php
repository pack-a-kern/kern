<?php

declare(strict_types=1);

namespace Kern\Documents\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Kern\Documents\FileSecurity\SniffedFileType;
use Kern\Platform\Settings\Services\AiProviderCatalogue;
use Kern\Platform\Settings\Services\ResolveAiProviderCredentials;
use RuntimeException;

/**
 * `POST /api/v1/subjects/{subject}/documents/enhance` — a photographed document, cleaned up.
 *
 * The `docscanner` extension: deskew, crop to the page, flatten the lighting. A phone photograph of
 * an invoice on a desk becomes something a model can read, which is the difference between an
 * extraction that works and one that returns nothing.
 *
 * Returns the improved image; it stores nothing. The upload screen sends the result instead of the
 * original, so what arrives is one file — the version a person looked at and accepted.
 *
 * **Why this runs before the virus scan, unusually.** Nothing else in this application sends bytes
 * anywhere before ClamAV has seen them. The alternative was enhancing after the scan and replacing
 * the stored object, which keeps that rule but leaves the person waiting on a queue for a picture
 * they are looking at. That trade was made deliberately (see `docs/adr/0005`), and the exposure is
 * bounded: the bytes are validated as a real image here first, and the provider is one the operator
 * already sends every document to.
 */
final class EnhanceImageController
{
    public function __invoke(
        Request $request,
        string $subject,
        ResolveAiProviderCredentials $resolveCredentials,
        AiProviderCatalogue $catalogue,
        HttpFactory $http,
    ): JsonResponse {
        if (! $catalogue->supportsEnhancement()) {
            return response()->json([
                'message' => 'This provider cannot enhance documents.',
                'reason' => 'ENHANCEMENT_UNAVAILABLE',
            ], 409);
        }

        $data = $request->validate(['file' => ['required', 'file']]);
        /** @var UploadedFile $file */
        $file = $data['file'];
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The uploaded file has no readable temporary path.');
        }

        $bytes = file_get_contents($path);

        if ($bytes === false) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        // Sniffed, not trusted: the declared type decides nothing here either, and only a real
        // photograph has anything to gain from being cleaned up.
        $type = SniffedFileType::sniff(substr($bytes, 0, SniffedFileType::SNIFF_WINDOW_BYTES));

        if ($type === null || ! $type->isImage()) {
            return response()->json([
                'message' => 'Only a photograph can be enhanced.',
                'reason' => 'NOT_AN_IMAGE',
            ], 422);
        }

        $credentials = ($resolveCredentials)();

        if (! $credentials->enabled || $credentials->baseUrl === null || $credentials->model === null) {
            return response()->json([
                'message' => 'No AI provider is configured.',
                'reason' => 'ENHANCEMENT_UNAVAILABLE',
            ], 409);
        }

        $dialect = $catalogue->dialectFor($credentials->provider);

        try {
            $response = $http
                ->timeout($catalogue->extractionTimeoutSeconds())
                ->withHeaders($credentials->apiKey === null
                    ? $dialect->unauthenticatedHeaders($catalogue->anthropicVersion())
                    : $dialect->authHeaders($credentials->apiKey, $catalogue->anthropicVersion()))
                ->post(rtrim($credentials->baseUrl, '/') . $dialect->chatPath(), [
                    // Required by the schema, unused by `scan_only` — the gateway's own contract.
                    'model' => $credentials->model,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [[
                            'type' => 'image_url',
                            'image_url' => ['url' => "data:{$type->value};base64," . base64_encode($bytes)],
                        ]],
                    ]],
                    'docscanner' => ['scan_only' => true],
                ]);
        } catch (ConnectionException) {
            // Never forward transport-exception text: it can embed the request URI, and this one
            // carries somebody's document.
            return response()->json([
                'message' => 'The enhancement service could not be reached.',
                'reason' => 'ENHANCEMENT_FAILED',
            ], 502);
        }

        if ($response->failed()) {
            return response()->json([
                'message' => 'The enhancement service refused the image.',
                'reason' => 'ENHANCEMENT_FAILED',
            ], 502);
        }

        $image = $this->imageFrom($response->json(), $type);

        if ($image === null) {
            // The shape is an extension, not a standard, and a gateway that answers 200 without an
            // image we recognise is telling us something about its contract. The *keys* are logged,
            // never the values — a value here is somebody's document.
            Log::info('document_enhancement_unrecognised_reply', [
                'keys' => $this->shapeOf($response->json()),
            ]);

            return response()->json([
                'message' => 'The enhancement service returned no image.',
                'reason' => 'ENHANCEMENT_FAILED',
            ], 502);
        }

        return response()->json(['image' => $image]);
    }

    /**
     * The cleaned-up image out of whatever shape the gateway wrapped it in.
     *
     * Several are tried because this is an extension rather than a standard: a gateway may answer
     * with its own key, or fold the result back into the reply's content. A shape nobody recognises
     * returns null rather than a guess — a wrong image is worse than an honest failure.
     */
    /**
     * The reply's structure, with nothing of its content.
     *
     * Keys only, two levels deep: enough to learn what a gateway calls the thing it returned,
     * without putting a person's document in a log file.
     *
     * @return list<string>
     */
    private function shapeOf(mixed $body): array
    {
        if (! is_array($body)) {
            return [gettype($body)];
        }

        $keys = [];

        foreach ($body as $key => $value) {
            $keys[] = is_array($value)
                ? $key . '{' . implode(',', array_slice(array_map('strval', array_keys($value)), 0, 8)) . '}'
                : (string) $key;
        }

        return $keys;
    }

    private function imageFrom(mixed $body, SniffedFileType $sent): ?string
    {
        // What this gateway actually answers with: raw base64 beside a `format`, not a data URI —
        // so the URI is built here. The paths below are tried after it because this is an extension
        // rather than a standard, and another gateway may fold the result into the reply's content.
        $raw = data_get($body, 'image_b64');

        if (is_string($raw) && $raw !== '') {
            $format = data_get($body, 'format');
            // The format it reports, or failing that the type we sent: an enhanced photograph comes
            // back a photograph, and mislabelling the bytes would break every reader downstream.
            $mime = is_string($format) && $format !== ''
                ? (str_contains($format, '/') ? $format : 'image/' . ltrim($format, '.'))
                : $sent->value;

            return 'data:' . $mime . ';base64,' . $raw;
        }

        foreach (['docscanner.image', 'image', 'data.0.url', 'choices.0.message.content'] as $path) {
            $value = data_get($body, $path);

            if (is_string($value) && str_starts_with($value, 'data:image/')) {
                return $value;
            }
        }

        return null;
    }
}
