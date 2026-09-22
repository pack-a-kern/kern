<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Legislatie;

use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * One act as the portal's web service describes it.
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
