<?php

declare(strict_types=1);

namespace Kern\Legislation\Retrieval\Query;

use DateTimeImmutable;
use InvalidArgumentException;
use Kern\Legislation\DataTransferObjects\LawFilter;
use Kern\Legislation\Enums\SectionType;

/**
 * `$asOf` is required, not defaulted to "today" — `BLUEPRINT.md` §15.2's hard requirement is
 * temporal correctness, and a caller must state which date it means, exactly like
 * `Kern\Legislation\Services\LegislationTemporalQuery::versionInForceOn()` already requires
 * an explicit date rather than assuming "now". A question phrased as "what applies today" is still
 * a caller decision — it should pass today's date explicitly (`Europe/Bucharest`, per `BLUEPRINT.md`
 * §18), not rely on this class silently choosing it.
 */
final readonly class HybridSearchQuery
{
    /**
     * @param list<string>|null $sourceCodes restrict to these `ext_official_sources.code` values (e.g. `['LEGISLATIE']`)
     * @param LawFilter|null $laws search only the laws the laws list shows under the same filter
     * @param int|null $limit how many results; null, as many as `legislation.search.results` says
     */
    public function __construct(
        public string $queryText,
        public DateTimeImmutable $asOf,
        public ?array $sourceCodes = null,
        public ?SectionType $sectionType = null,
        public ?LawFilter $laws = null,
        public ?int $limit = null,
    ) {
        if (trim($this->queryText) === '') {
            throw new InvalidArgumentException('queryText must not be empty.');
        }

        if ($this->limit !== null && $this->limit < 1) {
            throw new InvalidArgumentException('limit must be at least 1.');
        }

        if ($this->sourceCodes === []) {
            throw new InvalidArgumentException('sourceCodes must be null or non-empty.');
        }
    }
}
