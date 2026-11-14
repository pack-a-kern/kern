<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Bvb;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Kern\Investments\Parsing\ParseFailure;

/**
 * The daily prices behind an instrument page's chart (`wapi.bvb.ro`), read strictly: a status, then a day
 * per bar (`t`, Unix seconds at its midnight UTC) and its close (`c`), as many of one as of the other.
 * "no_data" is a span with no trading in it; anything else fails, saying where. Each close is taken as the
 * text BVB wrote, never as a float.
 */
final class BvbHistoryParser
{
    private const string SOURCE = 'BVB price history';

    private const string CLOSES = '/"c"\s*:\s*\[([^\]]*)\]/';

    private const string NUMBER = '/\A\d{1,12}(?:\.\d{1,8})?\z/';

    /** @return list<DailyClose> oldest first, as BVB sends them */
    public static function parse(string $json): array
    {
        try {
            $answer = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ParseFailure::at(self::SOURCE, 'not JSON');
        }

        $status = is_array($answer) ? ($answer['s'] ?? null) : null;

        if ($status === 'no_data') {
            return [];
        }

        $days = is_array($answer) ? ($answer['t'] ?? null) : null;

        if ($status !== 'ok' || ! is_array($days) || preg_match(self::CLOSES, $json, $closes) !== 1) {
            throw ParseFailure::at(self::SOURCE, 'neither prices nor "no_data"');
        }

        $texts = $closes[1] === '' ? [] : array_map(trim(...), explode(',', $closes[1]));

        if (count($texts) !== count($days)) {
            throw ParseFailure::at(self::SOURCE, 'a close for each day, and not as many');
        }

        return array_map(static function (mixed $day, string $close, int $at): DailyClose {
            if (! is_int($day) || $day < 0 || ! is_numeric($close) || preg_match(self::NUMBER, $close) !== 1) {
                throw ParseFailure::at(self::SOURCE . ', bar ' . ($at + 1), 'not a day and a price');
            }

            return new DailyClose((new DateTimeImmutable('@' . $day))->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0), $close);
        }, array_values($days), $texts, array_keys($texts));
    }
}
