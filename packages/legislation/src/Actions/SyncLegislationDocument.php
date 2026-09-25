<?php

declare(strict_types=1);

namespace Kern\Legislation\Actions;

use DateTimeImmutable;
use Kern\Legislation\DataTransferObjects\LegislationSyncStarted;
use Kern\Legislation\Jobs\ReadNextConsolidation;
use Kern\Legislation\Legislatie\Consolidation;
use Kern\Legislation\Legislatie\ConsolidationPages;
use Kern\Legislation\Legislatie\Exceptions\PortalAnswerException;
use Kern\Legislation\Legislatie\PortalAct;
use Kern\Legislation\Legislatie\PortalSearch;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Repositories\OfficialSourceRepository;

/**
 * Reads a law from legislatie.just.ro with every consolidation it has had: found by its type, number
 * and year, never by an address typed in.
 *
 * Here, only the looking — the web service finds the act, its page leads to the consolidated form,
 * and that lists the consolidations with their dates. An act never amended has none: its own page is
 * its one version, in force from the date the portal gives — the decisions setting the minimum wage
 * are all of this kind. The act's description and that list are saved
 * on the document, and reading the consolidations is queued: one job each
 * ({@see ReadNextConsolidation}), because the Codul fiscal is ninety of them at 5 MB apiece.
 *
 * Run again, it reads only what is new. A consolidation already stored is recognised by its portal
 * id and never downloaded twice; one that failed last time is tried again.
 *
 * `$from` narrows the range: only consolidations from that date, and the one in force on it — the
 * text that applied on `$from` began earlier. `$portalId` picks one act where several share the type
 * and number that year — see {@see PortalSearch::find()}.
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

    public function __invoke(string $type, int $number, int $year, ?DateTimeImmutable $from = null, ?string $portalId = null): LegislationSyncStarted
    {
        $act = $this->search->find($type, $number, $year, $portalId);
        $found = $this->pages->forAct($act->link);

        if ($found === null) {
            $consolidatedForm = $this->pages->actPageUrl($act->link);
            $listed = [$this->onlyVersion($act, $consolidatedForm)];
        } else {
            $consolidatedForm = $found->listedOn;
            $listed = self::inRange($found->consolidations, $from);
        }

        $document = SourceDocument::query()->firstOrCreate(
            [
                'official_source_id' => $this->sources->idForCode(self::SOURCE),
                'document_type' => $act->typeKey(),
                'external_identifier' => self::identifier($act),
            ],
            ['canonical_url' => $consolidatedForm],
        );

        // Kept alongside anything an earlier, wider sync listed; a failure mark is dropped, so a
        // consolidation that could not be read last time is tried again. An earlier entry for a day
        // now listed under another id gives way to it.
        $now = array_map(static fn (Consolidation $c): array => $c->toArray(), $listed);
        $listedDays = array_column($now, 'ref', 'date');
        $list = [];

        foreach ($document->consolidations ?? [] as $entry) {
            if (! isset($listedDays[$entry['date']])) {
                $list[$entry['ref']] = ['date' => $entry['date'], 'ref' => $entry['ref'], 'url' => $entry['url']];
            }
        }

        foreach ($now as $entry) {
            $list[$entry['ref']] = $entry;
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

        $this->adoptReadVersions($document, $listedDays);

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

    /**
     * A consolidation already read, for a day the list now names by another id, is that day's —
     * renamed, not read again: the text is the same code on the same day, its provisions keep their
     * ids, and every value approved against one of them keeps its paragraph.
     *
     * @param  array<string, string>  $listedDays  day => the id it is listed under now
     */
    private function adoptReadVersions(SourceDocument $document, array $listedDays): void
    {
        $versions = SourceDocumentVersion::query()
            ->where('source_document_id', $document->id)
            ->whereNotNull('consolidation_ref')
            ->whereNotIn('consolidation_ref', array_values($listedDays))
            ->whereIn('effective_from', array_keys($listedDays))
            ->get(['id', 'effective_from']);

        foreach ($versions as $version) {
            $day = $version->effective_from?->format('Y-m-d');

            if ($day !== null && isset($listedDays[$day])) {
                $version->forceFill(['consolidation_ref' => $listedDays[$day]])->save();
            }
        }
    }

    /** An act never consolidated: its own page, as it stands, from the day it came into force. */
    private function onlyVersion(PortalAct $act, string $page): Consolidation
    {
        $ref = ConsolidationPages::documentId($page);

        if ($act->inForceFrom === null || $ref === null) {
            throw PortalAnswerException::noInForceDate($page);
        }

        return new Consolidation($act->inForceFrom, $ref, $page);
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
