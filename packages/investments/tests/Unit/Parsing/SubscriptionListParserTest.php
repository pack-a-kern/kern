<?php

declare(strict_types=1);

namespace Kern\Investments\Tests\Unit\Parsing\SubscriptionListParserTest;

use Kern\Investments\Enums\Programme;
use Kern\Investments\Parsing\ParseFailure;
use Kern\Investments\Parsing\Treasury\SubscriptionFigures;
use Kern\Investments\Parsing\Treasury\SubscriptionListParser;

/** @return list<list<string>> the Ministry's real list, as the processing service hands it back */
function grid(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__ . "/../../Fixtures/treasury/{$name}-grid.json"), true, flags: JSON_THROW_ON_ERROR)['rows'];
}

/** @param list<SubscriptionFigures> $figures */
function byYear(array $figures): array
{
    $years = array_count_values(array_map(fn (SubscriptionFigures $f): string => $f->months[0]->format('Y'), $figures));
    ksort($years);

    return $years;
}

/** A small Tezaur list, laid out as the Ministry's. */
function tezaur(array ...$issues): array
{
    return [[], ['', '2026'], ['', 'Cod emisiune', 'Dată emisiune', 'Maturitate', 'Rată anuală a dobânzii', 'Valoare subscrisă', 'Nr. subscrieri'], ...$issues, ['', 'Total an 2026', '', '', '', '100', '1']];
}

it('reads every Fidelis issue from the Ministry’s list, the repeated symbols as the list repeats them', function (): void {
    $figures = SubscriptionListParser::parse(Programme::FIDELIS, grid('fidelis'));
    $repeated = array_keys(array_filter(array_count_values(array_map(fn (SubscriptionFigures $f): string => $f->code, $figures)), fn (int $n): bool => $n > 1));

    expect($figures)->toHaveCount(231)
        ->and(byYear($figures))->toBe(['2020' => 6, '2021' => 12, '2022' => 15, '2023' => 20, '2024' => 31, '2025' => 76, '2026' => 71])
        ->and($repeated)->toEqualCanonicalizing(['R2602A', 'R2602B', 'R2702AE', 'R2802A', 'R3002A', 'R3202AE', 'R2707B'])
        ->and($figures[0]->code)->toBe('R2208A')
        ->and($figures[0]->months[0]->format('Y-m-d'))->toBe('2020-08-01')
        ->and($figures[0]->subscribedValueMinor)->toBe(56237680000)
        ->and($figures[0]->subscriptions)->toBe(5414)
        // A round's month is written on its first issue; the next takes it.
        ->and($figures[1]->months[0]->format('Y-m'))->toBe('2020-08');
});

it('reads a round the list writes as two months as both', function (): void {
    $round = array_values(array_filter(SubscriptionListParser::parse(Programme::FIDELIS, grid('fidelis')), fn (SubscriptionFigures $f): bool => count($f->months) === 2));

    expect($round)->toHaveCount(10)
        ->and(array_map(fn ($m): string => $m->format('Y-m'), $round[0]->months))->toBe(['2023-04', '2023-05']);
});

it('reads every Tezaur issue, a column to the right and a header that loses its dot in 2024', function (): void {
    $figures = SubscriptionListParser::parse(Programme::TEZAUR, grid('tezaur'));
    // The list writes issue 0217 as "2017" (2026-10-06 edition): kept as written, so it matches no
    // issue and is reported, never read as another.
    $codes = array_map(fn (int $n): string => $n === 217 ? '2017' : sprintf('%04d', $n), range(1, 227));

    expect($figures)->toHaveCount(227)
        ->and(array_map(fn (SubscriptionFigures $f): string => $f->code, $figures))->toEqualCanonicalizing($codes)
        ->and(byYear($figures))->toBe(['2018' => 3, '2019' => 36, '2020' => 14, '2021' => 32, '2022' => 23, '2023' => 26, '2024' => 27, '2025' => 36, '2026' => 30]);
});

it('takes an amount the workbook kept as a float for the amount it is', function (): void {
    $figures = SubscriptionListParser::parse(Programme::TEZAUR, tezaur(['', '0001', '2026-02-01 00:00:00', '1 an', '0.063', '776504368.9999998', '18976']));

    expect($figures[0]->subscribedValueMinor)->toBe(77650436900);
});

it('fails the whole list, saying where, at a line it cannot read', function (array $issue, string $message): void {
    expect(fn () => SubscriptionListParser::parse(Programme::TEZAUR, tezaur($issue)))->toThrow(ParseFailure::class, $message);
})->with([
    'an issue before any month' => [['', '0001', '', '1 an', '0.06', '100', '1'], 'TEZAUR subscriptions, row 4: an issue with no month above it'],
    'a month it does not know' => [['', '0001', '2026-02-15 00:00:00', '1 an', '0.06', '100', '1'], 'row 4: not a month the list writes'],
    'a month span backwards' => [['', '0001', 'May - Apr 2026', '1 an', '0.06', '100', '1'], 'row 4: not a month the list writes'],
    'an amount in words' => [['', '0001', '2026-02-01 00:00:00', '1 an', '0.06', 'o suta', '1'], 'row 4: not an amount'],
    'a negative amount' => [['', '0001', '2026-02-01 00:00:00', '1 an', '0.06', '-100', '1'], 'row 4: not an amount'],
    'a count with a fraction' => [['', '0001', '2026-02-01 00:00:00', '1 an', '0.06', '100', '1.5'], 'row 4: not a number of subscriptions'],
    'figures under no code' => [['', '<script>', '2026-02-01 00:00:00', '1 an', '0.06', '100', '1'], 'row 4: figures on a line that is not an issue or a total'],
]);

it('fails a list with no header it knows, or no issue', function (): void {
    expect(fn () => SubscriptionListParser::parse(Programme::FIDELIS, grid('tezaur')))->toThrow(ParseFailure::class, 'FIDELIS subscriptions: no issue found')
        ->and(fn () => SubscriptionListParser::parse(Programme::TEZAUR, tezaur()))->toThrow(ParseFailure::class, 'no issue found');
});
