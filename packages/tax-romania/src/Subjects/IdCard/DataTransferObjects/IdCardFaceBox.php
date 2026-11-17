<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\IdCard\DataTransferObjects;

/**
 * Where the cardholder's photograph sits on the card, as fractions of the image in [0, 1] — a
 * top-left corner (x, y) and a size (w, h). Fractions, not pixels, so the browser can crop the copy
 * it holds whatever its displayed size.
 *
 * The model is trusted nowhere (ADR 0028, ADR 0005): a box is accepted only if it is four real
 * numbers that describe a non-empty rectangle inside the image, clamped so it cannot run past the
 * edges. Anything else is no box at all — null — and the user simply sets an avatar by hand.
 */
final readonly class IdCardFaceBox
{
    private function __construct(
        public float $x,
        public float $y,
        public float $w,
        public float $h,
    ) {
    }

    public static function fromModel(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $x = self::fraction($value['x'] ?? null);
        $y = self::fraction($value['y'] ?? null);
        $w = self::fraction($value['w'] ?? null);
        $h = self::fraction($value['h'] ?? null);

        if ($x === null || $y === null || $w === null || $h === null) {
            return null;
        }

        // A box that starts at the edge cannot also have full width; clamp the size to what is left,
        // and refuse a box with nothing left to crop.
        $w = min($w, 1.0 - $x);
        $h = min($h, 1.0 - $y);

        if ($w <= 0.0 || $h <= 0.0) {
            return null;
        }

        return new self($x, $y, $w, $h);
    }

    /** A number in [0, 1], or null for anything that is not one. */
    private static function fraction(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $number = (float) $value;

        if (! is_finite($number) || $number < 0.0 || $number > 1.0) {
            return null;
        }

        return $number;
    }

    /** @return array{x: float, y: float, w: float, h: float} */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'w' => $this->w, 'h' => $this->h];
    }
}
