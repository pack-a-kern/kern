<?php

declare(strict_types=1);

namespace Kern\Legislation\Legislatie;

use DateTimeImmutable;
use Kern\Legislation\Parsing\RomanianDateParser;

/**
 * What an act's title line says about it.
 *
 * The web service's title carries more than its own fields do, and says it better:
 * "LEGE nr. 227 din 8 septembrie 2015 privind Codul fiscal EMITENT: PARLAMENTUL PUBLICAT ÎN:
 * MONITORUL OFICIAL nr. 688 din 10 septembrie 2015". Its `Emitent` field loses the diacritics
 * ("Pre?edintele României") where the title keeps them, and the Monitorul Oficial number and date
 * exist nowhere else.
 */
final readonly class PortalActTitle
{
    private const string ISSUER_MARK = '/\bEMITENT\s*:?/iu';

    private const string PUBLISHED_MARK = '/\bPUBLICAT\s+(?:ÎN|IN)\s*:?/iu';

    public function __construct(private string $title)
    {
    }

    /** The act's own name — "LEGE nr. 227 din 8 septembrie 2015 privind Codul fiscal". */
    public function name(): string
    {
        return self::clean(preg_split(self::ISSUER_MARK, $this->title, 2)[0] ?? $this->title);
    }

    public function issuer(): ?string
    {
        $parts = preg_split(self::ISSUER_MARK, $this->title, 2);

        if ($parts === false || count($parts) < 2) {
            return null;
        }

        $issuer = self::clean(preg_split(self::PUBLISHED_MARK, $parts[1], 2)[0] ?? '');

        return $issuer === '' ? null : $issuer;
    }

    /** The date in "… nr. 227 din 8 septembrie 2015 …", before the publication line. */
    public function actDate(): ?DateTimeImmutable
    {
        $name = $this->name();

        return preg_match('/\bdin\s+(\d{1,2}\s+\p{L}+\s+\d{4})/u', $name, $match) === 1
            ? RomanianDateParser::parse($match[1])
            : null;
    }

    /** The Monitorul Oficial issue, "688" or "688 bis". */
    public function journalNumber(): ?string
    {
        return preg_match('/\bnr\.\s*(\d+(?:\s+bis)?)/iu', $this->publication(), $match) === 1 ? $match[1] : null;
    }

    public function publishedOn(): ?DateTimeImmutable
    {
        return preg_match('/\bdin\s+(\d{1,2}\s+\p{L}+\s+\d{4})/u', $this->publication(), $match) === 1
            ? RomanianDateParser::parse($match[1])
            : null;
    }

    private function publication(): string
    {
        $parts = preg_split(self::PUBLISHED_MARK, $this->title, 2);

        return $parts === false || count($parts) < 2 ? '' : $parts[1];
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
