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

    /**
     * The act as it is kept between the pages of a queued search — every field, dates as `Y-m-d`.
     *
     * @return array{type_label: string, number: string, title: string, issuer: ?string, act_date: ?string, official_journal: ?string, official_journal_number: ?string, published_on: ?string, in_force_from: ?string, link: string}
     */
    public function toArray(): array
    {
        return [
            'type_label' => $this->typeLabel,
            'number' => $this->number,
            'title' => $this->title,
            'issuer' => $this->issuer,
            'act_date' => $this->actDate?->format('Y-m-d'),
            'official_journal' => $this->officialJournal,
            'official_journal_number' => $this->officialJournalNumber,
            'published_on' => $this->publishedOn?->format('Y-m-d'),
            'in_force_from' => $this->inForceFrom?->format('Y-m-d'),
            'link' => $this->link,
        ];
    }

    /**
     * An act kept by {@see toArray()}, read back. A field that is not the type it was written as is
     * read as absent rather than trusted — the row is this package's own, but a row is still data.
     *
     * @param  array<array-key, mixed>  $kept
     */
    public static function fromArray(array $kept): self
    {
        $text = static fn (string $key): ?string => is_string($kept[$key] ?? null) ? $kept[$key] : null;
        $date = static fn (string $key): ?DateTimeImmutable => ($value = $text($key)) === null
            ? null
            : (DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null);

        return new self(
            typeLabel: $text('type_label') ?? '',
            number: $text('number') ?? '',
            title: $text('title') ?? '',
            issuer: $text('issuer'),
            actDate: $date('act_date'),
            officialJournal: $text('official_journal'),
            officialJournalNumber: $text('official_journal_number'),
            publishedOn: $date('published_on'),
            inForceFrom: $date('in_force_from'),
            link: $text('link') ?? '',
        );
    }

    /** A type as filed: upper case, no diacritics, so `Hotărâre` and `HOTARARE` are one type. */
    public static function key(string $type): string
    {
        return strtoupper(trim(Str::ascii($type)));
    }
}
