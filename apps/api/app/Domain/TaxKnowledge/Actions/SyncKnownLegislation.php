<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Actions;

use App\Domain\TaxKnowledge\DataTransferObjects\LegislationSyncStarted;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Repositories\OfficialSourceRepository;
use DateTimeImmutable;

/**
 * Looks for new consolidations of every law already read.
 *
 * The laws are the ones in the database — whatever was brought in through the ingest task — so there
 * is no list to keep anywhere else. Each keeps the range it was read from: a law read from 2020 is
 * not suddenly read back to 2016.
 */
final readonly class SyncKnownLegislation
{
    private const string SOURCE = 'LEGISLATIE';

    public function __construct(
        private SyncLegislationDocument $sync,
        private OfficialSourceRepository $sources,
    ) {
    }

    /** @return list<LegislationSyncStarted> */
    public function __invoke(): array
    {
        $sourceId = $this->sources->findIdForCode(self::SOURCE);

        if ($sourceId === null) {
            return [];
        }

        $started = [];

        $documents = SourceDocument::query()
            ->where('official_source_id', $sourceId)
            ->whereNotNull('act_number')
            ->whereNotNull('act_date')
            ->get();

        foreach ($documents as $document) {
            $dates = array_map(static fn (array $entry): string => $entry['date'], $document->consolidations ?? []);

            $started[] = ($this->sync)(
                $document->document_type,
                (int) $document->act_number,
                (int) $document->act_date?->format('Y'),
                $dates === [] ? null : new DateTimeImmutable(min($dates)),
            );
        }

        return $started;
    }
}
