<?php

declare(strict_types=1);

namespace Kern\Legislation\Http;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Legislation\Legislatie\ImportableTypes;
use Kern\Legislation\Legislatie\PortalAct;
use Kern\Legislation\Models\PortalSearchRun;
use Kern\Legislation\Queries\LegislationLibrary;

/**
 * A portal search as the screen reads it: where it is, and its acts shaped for choosing one — each with
 * whether it can be read (its type is one the ingest task takes, of a year from `legislation.form.min_year`
 * to this one — what the task and an import accept) and, when it already has been, which law it is. Worked out here, at each answer, so a law read since the search started shows as read.
 *
 * `poll_after_ms` only while it runs: how soon to ask again when no message came over the socket.
 */
final readonly class PortalSearchAnswer
{
    public function __construct(
        private LegislationLibrary $library,
        private ImportableTypes $importable,
        private ConfigRepository $config,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(PortalSearchRun $search): array
    {
        $acts = $search->portalActs();
        $minYear = $this->config->integer('legislation.form.min_year');
        $thisYear = (int) date('Y');
        $read = $this->library->lawsByPortalId(array_values(array_filter(array_map(
            static fn (PortalAct $act): ?string => $act->portalId(),
            $acts,
        ))));

        return [
            'id' => $search->id,
            'status' => $search->status->value,
            'pages_read' => $search->pages_read,
            'retries' => $search->retries,
            // How many of the acts found are already laws here: the summary a finished search shows.
            'in_library' => count(array_filter($acts, static fn (PortalAct $act): bool => isset($read[$act->portalId() ?? '']))),
            'failure' => $search->failure?->value,
            'poll_after_ms' => $search->status->isFinished() ? null : $this->config->integer('legislation.portal_search.poll_after_ms'),
            'acts' => array_map(fn (PortalAct $act): array => [
                'type' => $act->typeKey(),
                'type_label' => $act->typeLabel,
                'number' => $act->number,
                'year' => $act->year(),
                'title' => $act->title,
                'issuer' => $act->issuer,
                'in_force_from' => $act->inForceFrom?->format('Y-m-d'),
                'portal_id' => $act->portalId(),
                'url' => $act->link,
                'importable' => $this->importable->includes($act)
                    && $act->year() !== null && $act->year() >= $minYear && $act->year() <= $thisYear
                    && $act->portalId() !== null,
                // Why one of a type read here is not: older than the laws this installation reads.
                'before_min_year' => $act->year() !== null && $act->year() < $minYear,
                'law_id' => $read[$act->portalId() ?? ''] ?? null,
            ], $acts),
        ];
    }
}
