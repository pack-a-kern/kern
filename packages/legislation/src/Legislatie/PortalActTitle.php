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

    /**
     * The title line as the SOAP service writes it, from a name and the issuer and publication a page
     * shows apart from it: "<name> EMITENT: <issuer> PUBLICAT ÎN: <publication>". A part the name already
     * carries is not added twice; one that is empty is left out.
     *
     * The portal's search page lays a result out two ways: the older carries both inside the name's
     * paragraph, the newer puts them in elements of their own beside it.
     */
    public static function line(string $name, ?string $issuer, ?string $publication): string
    {
        $line = self::clean($name);
        $issuer = $issuer === null ? '' : self::clean($issuer);
        $publication = $publication === null ? '' : self::clean($publication);

        if ($issuer !== '' && preg_match(self::ISSUER_MARK, $line) !== 1) {
            $line .= ' EMITENT: ' . $issuer;
        }

        if ($publication !== '' && preg_match(self::PUBLISHED_MARK, $line) !== 1) {
            $line .= ' PUBLICAT ÎN: ' . $publication;
        }

        return $line;
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

    /** Where it was published, "MONITORUL OFICIAL" — the publication line before its number. */
    public function journal(): ?string
    {
        $journal = self::clean(preg_split('/\bnr\./iu', $this->publication(), 2)[0] ?? '');

        return $journal === '' ? null : $journal;
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
