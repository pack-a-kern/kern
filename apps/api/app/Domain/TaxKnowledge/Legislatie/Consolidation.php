<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Legislatie;

use DateTimeImmutable;

/** One consolidation the portal lists: the law as it stood from `date`, at `url`. */
final readonly class Consolidation
{
    public function __construct(
        public DateTimeImmutable $date,
        /** The portal's own id for this consolidation — `312601` in `DetaliiDocument/312601`. */
        public string $ref,
        public string $url,
        /**
         * Whether its page is only a reference to the text — the law approving a code, whose
         * consolidations each point at the code as it stood that day — and is read through it.
         */
        public bool $throughReference = false,
    ) {
    }

    /** @return array{date: string, ref: string, url: string, through_reference?: true} */
    public function toArray(): array
    {
        return ['date' => $this->date->format('Y-m-d'), 'ref' => $this->ref, 'url' => $this->url, ...($this->throughReference ? ['through_reference' => true] : [])];
    }
}
