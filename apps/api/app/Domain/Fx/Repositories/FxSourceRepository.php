<?php

declare(strict_types=1);

namespace App\Domain\Fx\Repositories;

use App\Domain\Fx\Models\FxSource;
use App\Domain\TaxKnowledge\Repositories\OfficialSourceRepository;

/**
 * Resolves (find-or-create) the `fx_sources` row for a given code, linking it to the matching
 * `official_sources` row via `App\Domain\TaxKnowledge\Repositories\OfficialSourceRepository`'s
 * scalar-id read surface — never by importing `App\Domain\TaxKnowledge\Models\OfficialSource`
 * itself (`docs/AGENTS.md` §6, and see that class's own docblock for why `idForCode()` exists).
 */
final readonly class FxSourceRepository
{
    public function __construct(
        private OfficialSourceRepository $officialSources,
    ) {
    }

    public function resolve(string $code, int $priority = 1): FxSource
    {
        $officialSourceId = $this->officialSources->idForCode($code);

        return FxSource::query()->firstOrCreate(
            ['code' => $code],
            [
                'official_source_id' => $officialSourceId,
                'priority' => $priority,
            ],
        );
    }
}
