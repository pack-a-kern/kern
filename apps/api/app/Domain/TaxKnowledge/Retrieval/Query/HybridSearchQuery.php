<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Retrieval\Query;

use App\Domain\TaxKnowledge\Enums\SectionType;
use App\Domain\TaxKnowledge\Enums\TrustTier;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * `$asOf` is required, not defaulted to "today" — `BLUEPRINT.md` §15.2's hard requirement is
 * temporal correctness, and a caller must state which date it means, exactly like
 * `App\Domain\TaxKnowledge\Services\LegislationTemporalQuery::versionInForceOn()` already requires
 * an explicit date rather than assuming "now". A question phrased as "what applies today" is still
 * a caller decision — it should pass today's date explicitly (`Europe/Bucharest`, per `BLUEPRINT.md`
 * §18), not rely on this class silently choosing it.
 */
final readonly class HybridSearchQuery
{
    /**
     * @param list<string>|null $sourceCodes restrict to these `ext_official_sources.code` values (e.g. `['LEGISLATIE']`)
     * @param list<TrustTier>|null $trustTiers restrict to these trust tiers
     */
    public function __construct(
        public string $queryText,
        public DateTimeImmutable $asOf,
        public ?array $sourceCodes = null,
        public ?array $trustTiers = null,
        public ?SectionType $sectionType = null,
        public int $limit = 20,
    ) {
        if (trim($this->queryText) === '') {
            throw new InvalidArgumentException('queryText must not be empty.');
        }

        if ($this->limit < 1) {
            throw new InvalidArgumentException('limit must be at least 1.');
        }

        if ($this->sourceCodes === []) {
            throw new InvalidArgumentException('sourceCodes must be null or non-empty.');
        }

        if ($this->trustTiers === []) {
            throw new InvalidArgumentException('trustTiers must be null or non-empty.');
        }
    }
}
