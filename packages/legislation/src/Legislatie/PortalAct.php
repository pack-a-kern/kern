<?php

declare(strict_types=1);

namespace Kern\Legislation\Legislatie;

use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * One act as the portal describes it — its SOAP service or its search page, which say the same things.
 *
 * `typeLabel` is the type as written — `HOTĂRÂRE` — and {@see typeKey()} the same without
 * diacritics, which is what acts are compared and filed by. Nothing here is corrected: a field the
 * portal leaves out is null.
 */
final readonly class PortalAct
{
    public function __construct(
        public string $typeLabel,
        public string $number,
        public string $title,
        public ?string $issuer,
        public ?DateTimeImmutable $actDate,
        public ?string $officialJournal,
        public ?string $officialJournalNumber,
        public ?DateTimeImmutable $publishedOn,
        public ?DateTimeImmutable $inForceFrom,
        public string $link,
    ) {
    }

    /**
     * An act from what a search result says of it: its title line — the same on the SOAP service and
     * the search page, "LEGE nr. 227 din 8 septembrie 2015 privind … EMITENT: … PUBLICAT ÎN: …" — and
     * the few things beside it.
     *
     * The title is read for the issuer, the act's date and the Monitorul Oficial's number and date,
     * which it says better than the fields do; `$issuer` and `$officialJournal` are the result's own
     * fields, used only where the title does not say.
     */
    public static function fromTitle(
        string $typeLabel,
        string $number,
        string $title,
        ?string $issuer,
        ?string $officialJournal,
        ?DateTimeImmutable $inForceFrom,
        string $link,
    ): self {
        $line = new PortalActTitle($title);

        return new self(
            typeLabel: $typeLabel,
            number: $number,
            title: $line->name(),
            // The title's, which keeps its diacritics; the field's own loses them.
            issuer: $line->issuer() ?? $issuer,
            actDate: $line->actDate(),
            officialJournal: $officialJournal ?? $line->journal(),
            officialJournalNumber: $line->journalNumber(),
            publishedOn: $line->publishedOn(),
            inForceFrom: $inForceFrom,
            link: $link,
        );
    }

    public function typeKey(): string
    {
        return self::key($this->typeLabel);
    }

    /** The portal's own id for the act — `236118` in `…/DetaliiDocument/236118`. */
    public function portalId(): ?string
    {
        return ConsolidationPages::documentId($this->link);
    }

    public function year(): ?int
    {
        return $this->actDate === null ? null : (int) $this->actDate->format('Y');
    }

    /** A type as filed: upper case, no diacritics, so `Hotărâre` and `HOTARARE` are one type. */
    public static function key(string $type): string
    {
        return strtoupper(trim(Str::ascii($type)));
    }
}
