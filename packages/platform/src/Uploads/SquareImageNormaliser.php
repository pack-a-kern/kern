<?php

declare(strict_types=1);

namespace Kern\Platform\Uploads;

use GdImage;
use Kern\Platform\Uploads\Exceptions\UploadRejectedException;

/**
 * Turns whatever a person uploaded into one square image this installation produced itself.
 *
 * ## Re-encoding is the security control, not the resizing
 *
 * The bytes that get stored are bytes GD wrote from a pixel buffer. Nothing of the original survives: not
 * a trailing payload after the image data, not a comment block, not a polyglot file that is a valid PNG
 * and a valid something-else at once, and not the EXIF, which on anything taken with a phone carries the
 * GPS coordinates of wherever the photo was taken. A scanner looks for what it recognises; this removes
 * everything it was not asked about. The two are not alternatives — the scan still runs first.
 *
 * ## Reading the format from the bytes
 *
 * The browser's `Content-Type` and the filename's extension are both supplied by whoever is uploading, so
 * neither decides anything here. `getimagesizefromstring()` reads the actual header, and the decoder is
 * chosen from that; a file that says PNG and is not simply fails to decode.
 *
 * ## The dimension check happens before the decode
 *
 * A 60KB PNG can declare 40000x40000 and expand to about 6GB once decoded — a decompression bomb, which
 * is a memory exhaustion attack rather than a malware one, so the scanner has no opinion on it. The header
 * is read first and anything oversized is refused without ever being decoded.
 */
final readonly class SquareImageNormaliser
{
    /**
     * @param int $side        the output's width and height, in pixels
     * @param int $maxSide     the largest input dimension that will be decoded, per side
     * @param int $quality     WebP quality, 0-100
     */
    public function __construct(
        private int $side,
        private int $maxSide,
        private int $quality,
    ) {
    }

    /**
     * @param string $bytes the uploaded file's contents
     *
     * @return string WebP bytes, `$side` by `$side`
     *
     * @throws UploadRejectedException
     */
    public function normalise(string $bytes): string
    {
        /*
         * The shortest real image header is longer than this, and `getimagesizefromstring()` raises a
         * warning rather than returning false on bytes too short to hold one — which, under a handler
         * that turns warnings into exceptions, is a different failure than the refusal intended here.
         */
        if (strlen($bytes) < 32) {
            throw UploadRejectedException::notAnImage();
        }

        $size = @getimagesizefromstring($bytes);

        if ($size === false) {
            throw UploadRejectedException::notAnImage();
        }

        [$width, $height] = [(int) $size[0], (int) $size[1]];
        $type = $size[2];

        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw UploadRejectedException::notAnImage();
        }

        if ($width > $this->maxSide || $height > $this->maxSide) {
            throw UploadRejectedException::dimensionsTooLarge($width, $height, $this->maxSide);
        }

        if ($width < 1 || $height < 1) {
            throw UploadRejectedException::undecodable();
        }

        $source = @imagecreatefromstring($bytes);

        if (! $source instanceof GdImage) {
            throw UploadRejectedException::undecodable();
        }

        /*
         * No `imagedestroy()` anywhere here. It has done nothing since PHP 8.0 — a GdImage is an object
         * and is freed when the last reference goes — and 8.5 deprecates it, so calling it would emit a
         * deprecation on every upload while freeing nothing.
         */
        return $this->encode($this->squareFrom($source, $width, $height));
    }

    /**
     * The middle square of the image, resampled to the output size.
     *
     * Cropping to the centre rather than squashing to fit: a face in a 3:2 photograph survives having its
     * sides taken off and does not survive being compressed horizontally. The crop is the largest square
     * the image contains, so nothing is scaled up and a portrait keeps its middle rather than its top.
     */
    private function squareFrom(GdImage $source, int $width, int $height): GdImage
    {
        $edge = min($width, $height);
        $left = intdiv($width - $edge, 2);
        $top = intdiv($height - $edge, 2);

        /*
         * `max(1, ...)` is for the type checker rather than for the arithmetic: `side` comes from config
         * as a plain int, and GD's signature requires a positive one. A configured zero would otherwise
         * be a fatal error at the call; this makes it a 1x1 image, which is visibly wrong rather than an
         * exception during an upload.
         */
        $side = max(1, $this->side);
        $canvas = imagecreatetruecolor($side, $side);

        /*
         * Alpha is preserved through the resample.
         *
         * A true-colour canvas starts filled with opaque black, so a PNG with a transparent background
         * would come out as a face on a black square. The blending mode is turned off so the source's
         * alpha is copied rather than composited onto that black, and `imagesavealpha` keeps it through
         * the encode.
         */
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);

        if ($transparent !== false) {
            imagefilledrectangle($canvas, 0, 0, $side, $side, $transparent);
        }

        imagecopyresampled($canvas, $source, 0, 0, $left, $top, $side, $side, $edge, $edge);

        return $canvas;
    }

    /** @throws UploadRejectedException */
    private function encode(GdImage $canvas): string
    {
        ob_start();
        $ok = imagewebp($canvas, null, $this->quality);
        $encoded = (string) ob_get_clean();

        if (! $ok || $encoded === '') {
            throw UploadRejectedException::unencodable();
        }

        return $encoded;
    }
}
