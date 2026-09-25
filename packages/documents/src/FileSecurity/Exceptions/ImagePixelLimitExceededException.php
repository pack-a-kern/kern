<?php

declare(strict_types=1);

namespace Kern\Documents\FileSecurity\Exceptions;

use Kern\Documents\Enums\RejectionReason;

/**
 * An image's decoded pixel count (width x height) exceeds the configured ceiling —
 * `BLUEPRINT.md` §14.2 "image pixel limit", the decompression-bomb guard for raster images.
 */
final class ImagePixelLimitExceededException extends FileSecurityException
{
    public static function exceeds(int $maxPixels): self
    {
        return new self(sprintf('The image exceeds the maximum allowed pixel count of %d.', $maxPixels));
    }

    public function reason(): RejectionReason
    {
        return RejectionReason::IMAGE_PIXEL_LIMIT_EXCEEDED;
    }
}
