<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Actions;

use App\Domain\TaxKnowledge\DataTransferObjects\LegislationSyncStarted;
use App\Domain\TaxKnowledge\Jobs\ReadNextConsolidation;
use App\Domain\TaxKnowledge\Legislatie\Consolidation;
use App\Domain\TaxKnowledge\Legislatie\ConsolidationPages;
use App\Domain\TaxKnowledge\Legislatie\PortalAct;
use App\Domain\TaxKnowledge\Legislatie\PortalSearch;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Repositories\OfficialSourceRepository;
use DateTimeImmutable;

/**
 * Reads a law from legislatie.just.ro with every consolidation it has had: found by its type, number
 * and year, never by an address typed in.
 *
 * Here, only the looking — the web service finds the act, its page leads to the consolidated form,
 * and that lists the consolidations with their dates. The act's description and that list are saved
 * on the document, and reading the consolidations is queued: one job each
 * ({@see ReadNextConsolidation}), because the Codul fiscal is ninety of them at 5 MB apiece.
 *
 * Run again, it reads only what is new. A consolidation already stored is recognised by its portal
 * id and never downloaded twice; one that failed last time is tried again.
 *
 * `$from` narrows the range: only consolidations from that date, and the one in force on it — the
 * text that applied on `$from` began earlier.
 */
final readonly class SyncLegislationDocument
{
    private const string SOURCE = 'LEGISLATIE';

    public function __construct(
        private PortalSearch $search,
        private ConsolidationPages $pages,
        private OfficialSourceRepository $sources,
    ) {
    }

    public function __invoke(string $type, int $number, int $year, ?DateTimeImmutable $from = null): LegislationSyncStarted
    {
        $act = $this->search->find($type, $number, $year);
        $consolidatedForm = $this->pages->consolidatedFormUrl($act->link);
        $listed = self::inRange($this->pages->consolidations($consolidatedForm), $from);

        $document = SourceDocument::query()->firstOrCreate(
            [
                'official_source_id' => $this->sources->idForCode(self::SOURCE),
                'document_type' => $act->typeKey(),
                'external_identifier' => self::identifier($act),
            ],
            ['canonical_url' => $consolidatedForm],
        );

        // Kept alongside anything an earlier, wider sync listed; a failure mark is dropped, so a
        // consolidation that could not be read last time is tried again.
        $list = [];

        foreach ([...($document->consolidations ?? []), ...array_map(static fn (Consolidation $c): array => $c->toArray(), $listed)] as $entry) {
            $list[$entry['ref']] = ['date' => $entry['date'], 'ref' => $entry['ref'], 'url' => $entry['url']];
        }

        uasort($list, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        $document->fill([
            'canonical_url' => $consolidatedForm,
            'title' => $act->title,
            'issuer' => $act->issuer,
            'publication_date' => $act->publishedOn?->format('Y-m-d'),
            'act_type_label' => $act->typeLabel,
            'act_number' => $act->number,
            'act_date' => $act->actDate?->format('Y-m-d'),
            'official_journal' => $act->officialJournal,
            'official_journal_number' => $act->officialJournalNumber,
            'in_force_from' => $act->inForceFrom?->format('Y-m-d'),
            'portal_document_id' => ConsolidationPages::documentId($act->link),
            'consolidated_document_id' => ConsolidationPages::documentId($consolidatedForm),
            'consolidations' => array_values($list),
        ])->save();

        $stored = SourceDocumentVersion::query()
            ->where('source_document_id', $document->id)
            ->whereIn('consolidation_ref', array_keys($list))
            ->count();

        ReadNextConsolidation::dispatch($document->id);

        return new LegislationSyncStarted(
            sourceDocumentId: $document->id,
            title: $act->title,
            listed: count($listed),
            toRead: count($list) - $stored,
        );
    }

    /** How an act is filed: `LEGE 227/2015`. */
    public static function identifier(PortalAct $act): string
    {
        return sprintf('%s %s/%d', $act->typeKey(), $act->number, $act->year() ?? 0);
    }

    /**
     * The consolidations from `$from` on, with the one in force on `$from` itself.
     *
     * @param  list<Consolidation>  $consolidations  oldest first
     * @return list<Consolidation>
     */
    private static function inRange(array $consolidations, ?DateTimeImmutable $from): array
    {
        if ($from === null) {
            return $consolidations;
        }

        $kept = [];
        $inForce = null;

        foreach ($consolidations as $consolidation) {
            if ($consolidation->date <= $from) {
                $inForce = $consolidation;
            } else {
                $kept[] = $consolidation;
            }
        }

        return $inForce === null ? $kept : [$inForce, ...$kept];
    }
}
