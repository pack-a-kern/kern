<?php

declare(strict_types=1);

namespace Kern\Investments\Tests\Unit\Parsing\TreasuryOrderParserTest;

use Kern\Investments\Enums\PayoutKind;
use Kern\Investments\Parsing\Orders\OrderIssue;
use Kern\Investments\Parsing\Orders\OrderPayout;
use Kern\Investments\Parsing\Orders\TreasuryOrderParser;
use Kern\Investments\Parsing\ParseFailure;
use Kern\Legislation\Parsing\LegislatieJustRoHtmlParser;
use Kern\Legislation\Parsing\ParsedLegislationDocument;

/** A Ministry of Finance order as legislatie.just.ro serves it — public, the Official Gazette's text — changed where a test says. */
function order(string $name, array $changes = []): ParsedLegislationDocument
{
    $html = strtr((string) file_get_contents(__DIR__ . "/../../Fixtures/orders/{$name}.html"), $changes);

    return (new LegislatieJustRoHtmlParser())->parse($html);
}

/** @return list<string> */
function dates(OrderIssue $issue, PayoutKind $kind): array
{
    return array_values(array_map(
        fn (OrderPayout $p): string => $p->paymentDate->format('Y-m-d'),
        array_filter($issue->payouts, fn (OrderPayout $p): bool => $p->kind === $kind),
    ));
}

it('reads every issue a Fidelis order’s final terms set, as the order sets them', function (): void {
    $issues = TreasuryOrderParser::fidelis(order('fidelis-terms'));
    $first = $issues[0];
    $euro = $issues[2];

    expect(array_map(fn (OrderIssue $i): ?string => $i->symbol, $issues))->toBe(['R2810B', 'R2810A', 'R2910BE', 'R2910CE', 'R3010A', 'R3610A', 'R3610AE'])
        ->and($first->isin)->toBe('ROQEJCC1VXP7')
        ->and($first->currency)->toBe('RON')
        ->and($first->faceValue)->toBe('100')
        ->and($first->ratePercent)->toBe('7.60')
        ->and($first->issueDate->format('Y-m-d'))->toBe('2026-10-14')
        ->and($first->maturityDate->format('Y-m-d'))->toBe('2028-10-14')
        ->and(dates($first, PayoutKind::COUPON))->toBe(['2027-10-14', '2028-10-14'])
        ->and($first->payouts[0]->recordDate?->format('Y-m-d'))->toBe('2027-10-05')
        ->and(dates($first, PayoutKind::PRINCIPAL))->toBe(['2028-10-14'])
        ->and($euro->currency)->toBe('EUR')
        ->and($euro->ratePercent)->toBe('5.50')
        ->and(dates($issues[5], PayoutKind::COUPON))->toHaveCount(10);
});

it('reads every issue a Tezaur prospectus launches, each coupon with its period', function (): void {
    $issues = TreasuryOrderParser::tezaur(order('tezaur-prospectus'));
    $three = $issues[1];

    expect(array_map(fn (OrderIssue $i): ?string => $i->issueCode, $issues))->toBe(['0228', '0229', '0230'])
        ->and($three->isin)->toBeNull()
        ->and($three->currency)->toBe('RON')
        ->and($three->faceValue)->toBe('1')
        ->and($three->ratePercent)->toBe('7.00')
        ->and($three->issueDate->format('Y-m-d'))->toBe('2026-11-10')
        ->and($three->maturityDate->format('Y-m-d'))->toBe('2029-11-10')
        // A payment on a day that is not a working day moves, as the schedule prints it.
        ->and(dates($three, PayoutKind::COUPON))->toBe(['2027-11-10', '2028-11-10', '2029-11-12'])
        ->and($three->payouts[0]->periodStart?->format('Y-m-d'))->toBe('2026-11-10')
        ->and($three->payouts[0]->periodEnd?->format('Y-m-d'))->toBe('2027-11-09')
        ->and(dates($three, PayoutKind::PRINCIPAL))->toBe(['2029-11-12']);
});

it('reads a Tezaur prospectus that prints every schedule in one table, each issue its own', function (): void {
    // The three schedules one after another in a single table, each from its own "Cod emisiune" row.
    $cell = '<td style="border: 1px solid black;white-space:pre" colspan="3" align="" rowspan="">Cod emisiune';
    $issues = TreasuryOrderParser::tezaur(order('tezaur-prospectus', ['</table><table style="display: block; color:black;"><tr>' . $cell => '<tr>' . $cell]));

    expect(array_map(fn (OrderIssue $i): ?string => $i->issueCode, $issues))->toBe(['0228', '0229', '0230'])
        ->and(dates($issues[0], PayoutKind::COUPON))->toBe(['2027-11-10'])
        ->and(dates($issues[1], PayoutKind::COUPON))->toBe(['2027-11-10', '2028-11-10', '2029-11-12'])
        ->and(dates($issues[1], PayoutKind::PRINCIPAL))->toBe(['2029-11-12'])
        ->and(count(dates($issues[2], PayoutKind::COUPON)))->toBe(5)
        ->and(dates($issues[2], PayoutKind::PRINCIPAL))->toBe(['2031-11-10']);
});

it('reads an order of the other programme as no issue', function (): void {
    expect(TreasuryOrderParser::tezaur(order('fidelis-terms')))->toBe([])
        ->and(TreasuryOrderParser::fidelis(order('tezaur-prospectus')))->toBe([]);
});

it('fails a Fidelis order whose terms do not read, saying where', function (array $changes, string $message): void {
    expect(fn () => TreasuryOrderParser::fidelis(order('fidelis-terms', $changes)))->toThrow(ParseFailure::class, $message);
})->with([
    'an ISIN with a wrong check digit' => [['ROQEJCC1VXP7' => 'ROQEJCC1VXP8'], 'Fidelis order, Anexa nr. 1: an ISIN whose check digit is wrong'],
    'a rate that is not one' => [['7,60% pe an' => 'șapte la sută'], 'Anexa nr. 1: not a rate'],
    'a payment date that does not exist' => [['14 octombrie 2027, 14 octombrie 2028' => '31 februarie 2027, 14 octombrie 2028'], 'Anexa nr. 1: not a date'],
    'a face value in a currency it does not know' => [['100 lei' => '100 dolari'], 'Anexa nr. 1: not a face value'],
]);

it('fails a Tezaur prospectus whose issue has no schedule', function (): void {
    expect(fn () => TreasuryOrderParser::tezaur(order('tezaur-prospectus', ['Cod emisiune 0229' => 'Cod emisiune 9229'])))
        ->toThrow(ParseFailure::class, 'issue 2: no schedule for issue 0229');
});
