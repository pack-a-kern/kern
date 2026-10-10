<?php

declare(strict_types=1);

namespace Kern\Platform\Uploads\Exceptions;

use RuntimeException;

/**
 * An upload this installation will not keep, for any reason: infected, unreadable, too large, the wrong
 * kind of file, or a scanner that could not say.
 *
 * One exception rather than one per cause, and the reason is the message it carries. The causes are not
 * distinguishable to the person uploading — each ends the same way, with the file refused — and a
 * hierarchy would invite a `catch` that treats "the scanner is down" as recoverable. The `reason` is a key
 * the HTTP layer translates; the message is for the log.
 */
final class UploadRejectedException extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function infected(): self
    {
        // No signature name, ever. It is attacker-influenced text, and `BLUEPRINT.md` §4.10 keeps it out
        // of logs for the same reason it keeps a filename out of them.
        return new self('infected', 'The upload was rejected by the virus scanner.');
    }

    public static function scannerUnavailable(string $detail): self
    {
        return new self('scanner_unavailable', "The upload could not be scanned: {$detail}");
    }

    public static function tooLarge(int $bytes, int $limit): self
    {
        return new self('too_large', "The upload is {$bytes} bytes, over the {$limit} byte limit.");
    }

    public static function notAnImage(): self
    {
        return new self('not_an_image', 'The upload is not a JPEG, PNG or WebP image.');
    }

    public static function dimensionsTooLarge(int $width, int $height, int $limit): self
    {
        return new self(
            'dimensions_too_large',
            "The image is {$width}x{$height}, over the {$limit} pixel limit on either side.",
        );
    }

    public static function undecodable(): self
    {
        return new self('undecodable', 'The image declared a format it could not be decoded as.');
    }

    public static function unencodable(): self
    {
        return new self('unencodable', 'The resized image could not be encoded.');
    }
}
