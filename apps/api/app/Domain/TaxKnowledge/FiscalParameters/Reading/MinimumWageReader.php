<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Reading;

use App\Domain\TaxKnowledge\FiscalParameters\ParameterCatalog;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Models\SourceSection;
use App\Domain\TaxKnowledge\Parsing\RomanianDateParser;
use App\Domain\TaxKnowledge\Parsing\SectionText;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The minimum wage, from the Government's decisions setting it — every one read in (the "Add a law"
 * dialog finds them by their title).
 *
 * Each decision says it in one sentence: "Începând cu data de 1 ianuarie 2025, salariul de bază
 * minim brut pe țară garantat în plată … la suma de 4.050 lei lunar". That sentence's paragraph is
 * the value's source; the date is its start, or — "Începând cu data intrării în vigoare a prezentei
 * hotărâri" — the decision's own day in force. A value holds until the day before the next one: the
 * wage changed in October 2023, July 2024 and July 2026, not only in January.
 */
final readonly class MinimumWageReader
{
    public function __construct(
        private ParameterCatalog $catalog,
        private ConfigRepository $config,
    ) {
    }

    /** @return list<Reading> oldest first */
    public function read(): array
    {
        $parameter = $this->catalog->minimumWage();
        $title = $this->config->string('fiscal_parameters.minimum_wage.title');
        $pattern = str_replace('{date}', $this->config->string('legislation.notes.fragments.date'), $this->config->string('fiscal_parameters.minimum_wage.amount'));
        $found = [];

        $decisions = SourceDocument::query()
            ->where('document_type', $this->config->string('fiscal_parameters.minimum_wage.document_type'))
            ->get()
            ->filter(static fn (SourceDocument $document): bool => str_contains(SectionText::searchable($document->title ?? ''), $title));

        foreach ($decisions as $decision) {
            $versions = SourceDocumentVersion::query()
                ->where('source_document_id', $decision->id)
                ->whereNotNull('effective_from')
                ->orderBy('effective_from')
                ->get();

            foreach ($versions as $version) {
                $reading = $this->readVersion($parameter->code, $decision, $version, $pattern);

                // A decision amended keeps saying the same: the first version that says it is its source.
                if ($reading !== null) {
                    $found[$reading->validFrom->format('Y-m-d')] ??= $reading;
                }
            }
        }

        ksort($found);
        $readings = array_values($found);

        // Each until the day before the next.
        foreach ($readings as $index => $reading) {
            $next = $readings[$index + 1] ?? null;

            $readings[$index] = new Reading(
                $reading->parameterCode,
                $reading->validFrom,
                $next?->validFrom->modify('-1 day'),
                $reading->value,
                $reading->lawValues,
                $reading->origin,
                $reading->checks,
                $reading->sourceSectionId,
            );
        }

        return $readings;
    }

    private function readVersion(string $code, SourceDocument $decision, SourceDocumentVersion $version, string $pattern): ?Reading
    {
        $sections = SourceSection::query()
            ->where('document_version_id', $version->id)
            ->orderBy('ordinal')
            ->get(['id', 'citation_label', 'text', 'tables']);

        foreach ($sections as $section) {
            if (preg_match($pattern, $section->fullText(), $match) !== 1) {
                continue;
            }

            $from = ($match['date'] ?? '') === '' ? $version->effective_from?->toDateTimeImmutable() : RomanianDateParser::parse($match['date']);

            if ($from === null) {
                continue;
            }

            $amount = (int) str_replace('.', '', $match['amount']);

            return new Reading(
                parameterCode: $code,
                validFrom: DateTimeImmutable::createFromInterface($from)->setTime(0, 0),
                validUntil: null,
                value: ['amount_minor' => $amount * 100, 'currency' => $this->config->string('fiscal_parameters.currency')],
                lawValues: [],
                origin: [
                    'kind' => 'decision',
                    'law_id' => $decision->id,
                    'act' => $decision->external_identifier,
                    'citation' => $section->citation_label,
                    'quote' => $match[0],
                ],
                sourceSectionId: $section->id,
            );
        }

        return null;
    }
}
