<?php

declare(strict_types=1);

use Kern\Platform\Uploads\Exceptions\UploadRejectedException;
use Kern\Platform\Uploads\SquareImageNormaliser;

/**
 * The normaliser's job is to make the stored bytes ones this installation wrote. These are the proofs
 * of that, not of the resizing — the resizing is the visible part and the least important.
 */
function normaliser(int $side = 64, int $maxSide = 2000): SquareImageNormaliser
{
    return new SquareImageNormaliser($side, $maxSide, 82);
}

/** A JPEG of a known size, with a mark off-centre so a crop can be told from a squash. */
function sampleJpeg(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 20, 90, 200));
    imagefilledrectangle($image, 0, 0, 10, 10, imagecolorallocate($image, 250, 240, 10));

    ob_start();
    imagejpeg($image, null, 92);
    $bytes = (string) ob_get_clean();

    return $bytes;
}

function samplePng(int $width, int $height, bool $transparent = false): string
{
    $image = imagecreatetruecolor($width, $height);

    if ($transparent) {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    }

    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();

    return $bytes;
}

it('produces a square webp of the configured size, whatever shape went in', function (): void {
    foreach ([[900, 300], [300, 900], [500, 500], [70, 70]] as [$width, $height]) {
        $out = normaliser()->normalise(sampleJpeg($width, $height));
        $size = getimagesizefromstring($out);

        expect($size)->not->toBeFalse()
            ->and($size[0])->toBe(64)
            ->and($size[1])->toBe(64)
            ->and($size[2])->toBe(IMAGETYPE_WEBP);
    }
});

it('shares no bytes with what was uploaded, so a trailing payload cannot survive', function (): void {
    $payload = '<?php system($_GET["c"]); ?>';
    $out = normaliser()->normalise(sampleJpeg(400, 400) . $payload);

    expect($out)->not->toContain($payload)
        ->and($out)->not->toContain('<?php');
});

it('drops EXIF, which is where a phone writes the coordinates the photo was taken at', function (): void {
    /*
     * An APP1 segment carrying a GPS tag, spliced in after the JPEG's SOI marker where a camera puts
     * it. Hand-built rather than mocked: the point is that real bytes in a real segment do not come
     * out the other side, and a fake would only prove the test's own assumption.
     */
    $jpeg = sampleJpeg(300, 300);
    $exif = "Exif\x00\x00MM\x00\x2a\x00\x00\x00\x08GPSLatitude 44.4268N";
    $app1 = "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
    $withExif = substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);

    expect($withExif)->toContain('GPSLatitude');

    expect(normaliser()->normalise($withExif))
        ->not->toContain('GPSLatitude')
        ->not->toContain('Exif');
});

it('keeps transparency rather than compositing it onto black', function (): void {
    $out = normaliser()->normalise(samplePng(200, 200, transparent: true));
    $image = imagecreatefromstring($out);

    expect($image)->not->toBeFalse();

    // The alpha channel is 7 bits in GD: 127 is fully transparent, 0 fully opaque.
    $alpha = (imagecolorat($image, 2, 2) >> 24) & 0x7F;

    expect($alpha)->toBe(127);
});

it('takes the middle of a wide image rather than squashing it', function (): void {
    /*
     * The mark sits in the top-left ten pixels of a 900x300 source. The largest square that image
     * contains is the middle 300x300, which starts 300px in — so a centre crop loses the mark, and a
     * squash to fit would keep it. Its absence is the assertion.
     */
    $out = normaliser()->normalise(sampleJpeg(900, 300));
    $image = imagecreatefromstring($out);

    expect($image)->not->toBeFalse();

    $corner = imagecolorat($image, 2, 2);

    $red = ($corner >> 16) & 0xFF;
    $blue = $corner & 0xFF;

    expect($red)->toBeLessThan(120)->and($blue)->toBeGreaterThan(120);
});

it('refuses anything that is not a JPEG, PNG or WebP', function (string $bytes): void {
    expect(fn () => normaliser()->normalise($bytes))
        ->toThrow(UploadRejectedException::class);
})->with([
    'plain text' => 'this is not an image',
    'a PHP file wearing a GIF header' => "GIF89a<?php echo 1; ?>",
    'empty' => '',
    'an SVG, which is a document that can carry script' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
]);

it('refuses a declared size that would exhaust memory, without decoding it', function (): void {
    /*
     * A decompression bomb is cheap to send and expensive to open: the dimensions come from the header
     * and the allocation is width x height x 4 regardless of the file's weight. The guard reads the
     * header, so this must be refused rather than decoded — which is also why the fixture here is only
     * 3000 pixels wide and the limit is 2000.
     */
    $bomb = samplePng(3000, 10);

    expect(strlen($bomb))->toBeLessThan(200_000);

    try {
        normaliser(maxSide: 2000)->normalise($bomb);
        expect(false)->toBeTrue('an oversized image was accepted');
    } catch (UploadRejectedException $e) {
        expect($e->reason)->toBe('dimensions_too_large');
    }
});

it('names the reason, so a screen can say what to do about it', function (): void {
    try {
        normaliser()->normalise('not an image');
        expect(false)->toBeTrue('a non-image was accepted');
    } catch (UploadRejectedException $e) {
        expect($e->reason)->toBe('not_an_image');
    }
});
