<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Avatars;

use Illuminate\Support\Carbon;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Uploads\Contracts\UploadScanner;
use Kern\Platform\Uploads\Exceptions\UploadRejectedException;
use Kern\Platform\Uploads\SquareImageNormaliser;

/**
 * Accepts a picture for somebody's account, or refuses it.
 *
 * The order of the four steps is the whole design, and it is: weigh it, scan it, rebuild it, keep it.
 *
 *  1. **Weigh it.** Before anything is in memory twice. A request body has already been read by the time
 *     this runs, but the decoded image is the expensive copy and nothing should reach it on a file that
 *     was never going to be accepted.
 *  2. **Scan it**, on the bytes as uploaded. Scanning after the rebuild would scan something this
 *     installation wrote, which is a test of GD rather than of the upload, and the scanner's report would
 *     be about a file that no longer exists.
 *  3. **Rebuild it.** {@see SquareImageNormaliser} decodes and re-encodes, so what gets stored shares no
 *     bytes with what arrived — no trailing payload, no EXIF, no polyglot.
 *  4. **Keep it**, then record it. The audit row names the person whose face changed and the person who
 *     changed it, which are not always the same, and nothing of the image itself.
 *
 * A failure at any step throws, and nothing has been written at the point any of them can.
 */
final readonly class SetAvatar
{
    public function __construct(
        private UploadScanner $scanner,
        private SquareImageNormaliser $normaliser,
        private AvatarStore $store,
        private AuditChainService $audit,
        private int $maxBytes,
    ) {
    }

    /**
     * @param string $bytes    the uploaded file's contents
     * @param ?string $actorId whoever is making the change, if there is one
     *
     * @throws UploadRejectedException
     */
    public function __invoke(HasAvatar $owner, string $bytes, ?string $actorId): HasAvatar
    {
        $length = strlen($bytes);

        if ($length > $this->maxBytes) {
            throw UploadRejectedException::tooLarge($length, $this->maxBytes);
        }

        if ($length === 0) {
            throw UploadRejectedException::notAnImage();
        }

        $this->scanner->scanOrReject($this->streamOf($bytes));

        $image = $this->normaliser->normalise($bytes);

        $ownerId = $owner->avatarOwnerId();
        $ownerType = $owner->avatarOwnerType();

        $owner->saveAvatar($this->store->put($ownerId, $image), Carbon::now());

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $actorId,
            eventType: strtoupper($ownerType) . '_AVATAR_SET',
            resourceType: $ownerType,
            resourceId: $ownerId,
            // The size, not the image and not its name. A filename is attacker-supplied text and the
            // bytes are a face; neither belongs in a chain anybody with audit access can read.
            payloadHash: $this->audit->hashPayload(['bytes' => strlen($image)]),
        ));

        return $owner;
    }

    /** @return resource */
    private function streamOf(string $bytes)
    {
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw UploadRejectedException::scannerUnavailable('no temporary stream could be opened');
        }

        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }
}
