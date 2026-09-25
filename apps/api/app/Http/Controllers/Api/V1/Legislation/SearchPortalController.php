<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Legislation;

use App\Domain\TaxKnowledge\Fetching\Exceptions\UnexpectedStatusException;
use App\Domain\TaxKnowledge\Legislatie\Exceptions\PortalAnswerException;
use App\Domain\TaxKnowledge\Legislatie\PortalAct;
use App\Domain\TaxKnowledge\Legislatie\PortalSearch;
use App\Domain\TaxKnowledge\Queries\LegislationLibrary;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/legislation/portal` — acts on legislatie.just.ro to choose one from before reading it:
 * by words of the title (`title`, and `year` if wanted), or every act of a number that year
 * (`number` and `year`) — of one type only, if `type` names one of those the ingest task takes.
 *
 * Only a search: reading an act is the `legislation.ingest` task, started with the type, number,
 * year and portal id of the result chosen — so it is locked, audited and in the run history like
 * every other task. Each result says whether it can be read (its type is one the task offers) and,
 * when it already has been, which law it is.
 */
final class SearchPortalController
{
    public function __invoke(Request $request, PortalSearch $search, LegislationLibrary $library, ConfigRepository $config): JsonResponse
    {
        $importable = array_map(
            static fn (mixed $type): string => PortalAct::key(is_string($type) ? $type : ''),
            $config->array('operations.legislation.document_types'),
        );

        /** @var array{title?: string|null, number?: int|string|null, year?: int|string|null, type?: string|null} $query */
        $query = $request->validate([
            'title' => ['nullable', 'required_without:number', 'prohibits:number', 'string', 'min:3', 'max:200'],
            'number' => ['nullable', 'required_without:title', 'integer', 'min:1', 'max:99999'],
            'year' => ['nullable', 'required_with:number', 'integer', 'min:' . $config->integer('legislation.form.min_year'), 'max:' . date('Y')],
            'type' => ['nullable', Rule::in($importable)],
        ]);

        $year = isset($query['year']) ? (int) $query['year'] : null;

        try {
            $acts = isset($query['number'])
                ? $search->byNumber((int) $query['number'], (int) $year)
                : $search->byTitle(trim((string) ($query['title'] ?? '')), $year);
        } catch (PortalAnswerException|UnexpectedStatusException|ConnectionException) {
            return response()->json(['message' => 'legislatie.just.ro did not answer the search.'], 502);
        }

        $type = $query['type'] ?? null;

        if ($type !== null) {
            $acts = array_values(array_filter($acts, static fn (PortalAct $act): bool => $act->typeKey() === $type));
        }

        $imported = $library->lawsByPortalId(array_values(array_filter(array_map(static fn (PortalAct $act): ?string => $act->portalId(), $acts))));

        return response()->json([
            'acts' => array_map(static fn (PortalAct $act): array => [
                'type' => $act->typeKey(),
                'type_label' => $act->typeLabel,
                'number' => $act->number,
                'year' => $act->year(),
                'title' => $act->title,
                'issuer' => $act->issuer,
                'in_force_from' => $act->inForceFrom?->format('Y-m-d'),
                'portal_id' => $act->portalId(),
                'url' => $act->link,
                'importable' => in_array($act->typeKey(), $importable, true) && $act->year() !== null && $act->portalId() !== null,
                'law_id' => $imported[$act->portalId() ?? ''] ?? null,
            ], $acts),
        ]);
    }
}
