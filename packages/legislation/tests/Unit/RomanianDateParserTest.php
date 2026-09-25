<?php

declare(strict_types=1);

use Kern\Legislation\Parsing\RomanianDateParser;

it('parses a Romanian "D month YYYY" date embedded in a longer string', function (): void {
    $date = RomanianDateParser::parse('MONITORUL OFICIAL nr. 498 din 19 iulie 2010');

    expect($date?->format('Y-m-d'))->toBe('2010-07-19');
});

it('parses every Romanian month name', function (): void {
    $months = [
        'ianuarie' => 1, 'februarie' => 2, 'martie' => 3, 'aprilie' => 4,
        'mai' => 5, 'iunie' => 6, 'iulie' => 7, 'august' => 8,
        'septembrie' => 9, 'octombrie' => 10, 'noiembrie' => 11, 'decembrie' => 12,
    ];

    foreach ($months as $name => $number) {
        $date = RomanianDateParser::parse("1 {$name} 2025");
        expect($date?->format('n'))->toBe((string) $number);
    }
});

it('returns null for unparseable text rather than guessing', function (): void {
    expect(RomanianDateParser::parse('nu exista nicio data aici'))->toBeNull()
        ->and(RomanianDateParser::parse('32 ianuarie 2025'))->toBeNull()
        ->and(RomanianDateParser::parse(''))->toBeNull();
});
