<?php

declare(strict_types=1);

namespace Tests\Unit\Legislation\SectionTextTest;

use Kern\Legislation\Parsing\SectionText;

/**
 * When two versions of a provision say the same thing.
 *
 * The history of every article rests on this: a fingerprint that moves on a reformatting shows a
 * change where none happened, and one that misses a changed number hides the only change that matters.
 */
it('ignores formatting, case, punctuation and the two spellings of ș and ț', function (): void {
    expect(SectionText::fingerprint("Contribuţia se datorează\nlunar, la  baza de calcul."))
        ->toBe(SectionText::fingerprint('contribuția se datorează lunar la baza de calcul'));
});

it('changes when a number changes', function (): void {
    expect(SectionText::fingerprint('baza este de 6 salarii minime'))
        ->not->toBe(SectionText::fingerprint('baza este de 12 salarii minime'));
});

it('reads a decimal comma and a decimal point as the same number', function (): void {
    expect(SectionText::fingerprint('cota de 16,5%'))->toBe(SectionText::fingerprint('cota de 16.5%'));
});

it('leaves the portal\'s amendment notes out, brackets inside them included', function (): void {
    $withNote = "Venitul impozabil se stabilește anual.\n(la 01-01-2025, Alineatul (2) din Articolul 170 a fost modificat de Punctul 22.)";

    expect(SectionText::fingerprint($withNote))->toBe(SectionText::fingerprint('Venitul impozabil se stabilește anual.'));
});

it('dates a provision by its latest amendment note', function (): void {
    $text = "(la 01-01-2023, Litera a) a fost modificată.)\nText.\n(la 01-08-2025, Alineatul (1) a fost modificat.)";

    expect(SectionText::amendedOn($text))->toBe('2025-08-01')
        ->and(SectionText::amendedOn('Text fără note.'))->toBeNull();
});

it('writes a table out row by row where its marker stands', function (): void {
    expect(SectionText::withTables("Tabel:\n[[table:1]]\nSfârșit.", [[['a', 'b'], ['1', '2']]]))
        ->toBe("Tabel:\na | b\n1 | 2\nSfârșit.");
});

it('reads a text as a search compares it: words and numbers, lower case, no diacritics', function (): void {
    expect(SectionText::searchable("Cota de contribuţie de asigurări sociale de sănătate\neste de 10% și se datorează"))
        ->toBe('cota de contributie de asigurari sociale de sanatate este de 10 si se datoreaza')
        ->and(SectionText::searchable('Titlul V Capitolul III Secţiunea a 2-a'))->toBe(SectionText::searchable('TITLUL V CAPITOLUL III SECȚIUNEA A 2-A'));
});
