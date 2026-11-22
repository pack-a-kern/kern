<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Subjects;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Documents\FileSecurity\SniffedFileType;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Services\ResolveAiProviderCredentials;
use Kern\Platform\Settings\Services\ResolveTaskModel;
use Kern\Platform\Uploads\Contracts\UploadScanner;
use Kern\Platform\Uploads\Exceptions\UploadRejectedException;
use Kern\TaxRomania\Subjects\IdCard\Jobs\ReadIdCardJob;
use Kern\TaxRomania\Subjects\IdCard\Services\IdCardScratch;

/**
 * `POST /api/v1/subjects/id-card/read` — begins reading an identity card to pre-fill a new taxpayer
 * (ADR 0028).
 *
 * This runs *before* any taxpayer exists, so it is a sibling of the CNP check, not an action on a
 * subject. Consent is the acting user's, given here and now (`consent` must be accepted) and recorded
 * against them naming the provider — never the per-subject `DocumentAiConsent`, which has no subject
 * to attach to yet.
 *
 * The card never comes to rest in the clear: its bytes are sealed into {@see IdCardScratch} under an
 * unguessable token, the PHP upload temp file is unlinked the instant it is read, and the queued
 * {@see ReadIdCardJob} carries only the token. The declared MIME decides nothing (BLUEPRINT §14.2) —
 * the bytes are sniffed, and only a real image is accepted. The answer comes back over the socket;
 * this returns at once.
 */
final class ReadIdCardController
{
    public function __invoke(
        Request $request,
        ResolveAiProviderCredentials $resolveCredentials,
        IdCardScratch $scratch,
        AuditChainService $audit,
        ConfigRepository $config,
        UploadScanner $scanner,
        ResolveTaskModel $resolveTaskModel,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $request->validate([
            'file' => ['required', 'file'],
            // The user's own decision to send this card to the provider, made here because there is
            // no subject to carry a stored consent.
            'consent' => ['accepted'],
        ]);

        $credentials = ($resolveCredentials)();
        $model = $resolveTaskModel(SettingKey::AI_ID_CARD_MODEL) ?? $credentials->model;

        if (! $credentials->enabled || $credentials->baseUrl === null || $model === null) {
            return response()->json([
                'message' => __('tax-romania::id_card.unavailable'),
                'reason' => 'ID_CARD_READ_UNAVAILABLE',
            ], 409);
        }

        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            return response()->json(['message' => __('tax-romania::id_card.missing'), 'reason' => 'NO_FILE'], 422);
        }

        // Turned away by the temp file's own size, before a byte is read into memory: an oversized
        // upload never gets buffered (ADR 0028).
        $size = $file->getSize();

        if ($size !== false && $size > $config->integer('ai.idcard.read.max_image_bytes')) {
            return response()->json(['message' => __('tax-romania::id_card.too_large'), 'reason' => 'ID_CARD_TOO_LARGE'], 413);
        }

        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            // No path to read and nothing to clean up — PHP discards the temp file at shutdown. Treated
            // as an unreadable upload, not a 500, so the screen can fall back to typing it by hand.
            return response()->json(['message' => __('tax-romania::id_card.unreadable'), 'reason' => 'UNREADABLE'], 422);
        }

        $bytes = file_get_contents($path);

        // The one place a card touches disk is PHP's own upload temp file, written before our code
        // ran; unlink it the moment its bytes are in memory (ADR 0028).
        @unlink($path);

        if ($bytes === false) {
            return response()->json(['message' => __('tax-romania::id_card.unreadable'), 'reason' => 'UNREADABLE'], 422);
        }

        // Sniffed, not trusted: the declared type and extension decide nothing.
        $type = SniffedFileType::sniff(substr($bytes, 0, SniffedFileType::SNIFF_WINDOW_BYTES));

        if ($type === null || ! $type->isImage()) {
            return response()->json(['message' => __('tax-romania::id_card.not_an_image'), 'reason' => 'NOT_AN_IMAGE'], 422);
        }

        // The same virus scan every kept upload gets (the avatar crop is scanned again on its way to
        // storage by SetAvatar). Run here, before the card is sealed into scratch or reaches the
        // provider, because this application scans bytes before they go anywhere. Fails closed: an
        // infected card — or a scanner that cannot be reached — is refused, never sent.
        try {
            $scanner->scanOrReject($this->streamOf($bytes));
        } catch (UploadRejectedException) {
            return response()->json(['message' => __('tax-romania::id_card.unsafe'), 'reason' => 'ID_CARD_UNSAFE'], 422);
        }

        $token = Str::random(64);
        $scratch->putImage($token, $bytes, $type->value);

        // Who sent a card to which provider — never the card, never a field. Recorded here, where the
        // person decided to send it.
        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $user->id,
            eventType: 'IdCardAiReadRequested',
            resourceType: 'IdCardRead',
            resourceId: $token,
            payloadHash: $audit->hashPayload(['provider' => $credentials->provider ?? '']),
        ));

        ReadIdCardJob::dispatch($token, $user->id);

        return response()->json(['token' => $token, 'status' => 'reading'], 202);
    }

    /**
     * The card bytes as a stream for the scanner, which rewinds and reads it (contract of
     * {@see UploadScanner}).
     *
     * @return resource
     */
    private function streamOf(string $bytes)
    {
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            // No stream, no scan: fail closed, exactly as a scanner that could not be reached would.
            throw UploadRejectedException::scannerUnavailable('no temporary stream could be opened');
        }

        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }
}
