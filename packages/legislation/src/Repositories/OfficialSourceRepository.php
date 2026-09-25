<?php

declare(strict_types=1);

namespace Kern\Legislation\Repositories;

use Kern\Legislation\Config\OfficialSourceRegistry;
use Kern\Legislation\Models\OfficialSource;

/**
 * Mirrors `config/sources.php` into the `ext_official_sources` table on demand. The config file is
 * always authoritative (see that migration's docblock) — `resolve()` upserts the DB row's
 * `trust_tier`/`base_url`/`enabled` from the current config every time, so the two can never
 * silently drift apart.
 *
 * This is the module's cross-boundary read surface for `Domain/Fx`:
 * {@see idForCode()} returns a bare id string precisely so `Domain/Fx` never needs to import
 * {@see OfficialSource} itself (`docs/AGENTS.md` §6 — a module never imports another module's
 * Eloquent models).
 */
final readonly class OfficialSourceRepository
{
    public function __construct(
        private OfficialSourceRegistry $registry,
    ) {
    }

    public function resolve(string $code): OfficialSource
    {
        $definition = $this->registry->find($code);

        return OfficialSource::query()->updateOrCreate(
            ['code' => $definition->code],
            [
                'trust_tier' => $definition->trustTier->value,
                'base_url' => $definition->baseUrl,
                'enabled' => $definition->enabled,
                'priority' => $definition->priority,
            ],
        );
    }

    public function idForCode(string $code): string
    {
        return $this->resolve($code)->id;
    }

    /**
     * The id of a source already in the table, without writing it there. For reads — a rate lookup
     * must not create a source row as a side effect of asking whether it has any rates.
     */
    public function findIdForCode(string $code): ?string
    {
        $id = OfficialSource::query()->where('code', $code)->value('id');

        return is_string($id) ? $id : null;
    }
}
