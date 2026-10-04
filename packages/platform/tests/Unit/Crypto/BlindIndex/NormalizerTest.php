<?php

declare(strict_types=1);

namespace Kern\Platform\Tests\Unit\Crypto\BlindIndex\NormalizerTest;

use Kern\Platform\Crypto\BlindIndex\Normalizer;

describe('email', function (): void {
    it('lowercases and trims', function (): void {
        expect(Normalizer::email('  Maria.Popescu@Example.RO  '))->toBe('maria.popescu@example.ro');
    });

    it('is deterministic for values that only differ by case', function (): void {
        expect(Normalizer::email('A@B.com'))->toBe(Normalizer::email('a@b.com'));
    });
});

describe('cnp', function (): void {
    it('strips non-digit formatting characters', function (): void {
        expect(Normalizer::cnp('1 900101-234567'))->toBe('1900101234567');
    });

    it('is unaffected by leading/trailing whitespace', function (): void {
        expect(Normalizer::cnp('  1900101234567  '))->toBe('1900101234567');
    });
});

describe('iban', function (): void {
    it('strips spaces and uppercases', function (): void {
        expect(Normalizer::iban('ro00 aaaa 1b31 0075 9384 0000'))->toBe('RO00AAAA1B31007593840000');
    });

    it('is deterministic regardless of grouping', function (): void {
        expect(Normalizer::iban('RO49AAAA1B3100759384 0000'))
            ->toBe(Normalizer::iban('RO49 AAAA1B31 00759384 0000'));
    });
});

describe('text (Romanian diacritics)', function (): void {
    it('folds comma-below diacritics to their base Latin letter', function (): void {
        // U+0219 (ș), U+021B (ț) — comma-below forms.
        expect(Normalizer::text('Ștefan Țăriceanu'))->toBe('stefan tariceanu');
    });

    it('folds cedilla diacritics to the same result as comma-below', function (): void {
        // U+015F (ş), U+0163 (ţ) — cedilla forms some legacy fonts/keyboards produce, as
        // opposed to U+0219/U+021B (ș/ț) — the comma-below forms used above.
        expect(Normalizer::text("\u{015F}tefan \u{0162}\u{0103}riceanu"))
            ->toBe(Normalizer::text('Ștefan Țăriceanu'));
    });

    it('folds ă, î, â', function (): void {
        expect(Normalizer::text('Câmpina, Bârlad, Mărășești'))->toBe('campina, barlad, marasesti');
    });

    it('collapses internal whitespace runs and trims', function (): void {
        expect(Normalizer::text("  Ion   Popescu\t\n"))->toBe('ion popescu');
    });

    it('is case-insensitive', function (): void {
        expect(Normalizer::text('BUCUREȘTI'))->toBe(Normalizer::text('bucurești'));
    });
});
