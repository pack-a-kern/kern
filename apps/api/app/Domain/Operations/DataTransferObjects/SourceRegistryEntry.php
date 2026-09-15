<?php

declare(strict_types=1);

namespace App\Domain\Operations\DataTransferObjects;

/**
 * One entry of the official-source allowlist (`BLUEPRINT.md` §7.1), as declared in
 * `config/sources.php`.
 *
 * `mirrored` is whether an `ext_official_sources` row exists for this code yet. The table is populated
 * lazily — `App\Domain\TaxKnowledge\Repositories\OfficialSourceRepository::resolve()` upserts a row
 * the first time a source is actually fetched — so an entry with `mirrored => false` is declared
 * and allowed but has never been used. Without this flag the panel could not distinguish "the
 * allowlist is empty" from "nothing has been imported yet", which are very different situations for
 * an operator to be looking at.
 */
final readonly class SourceRegistryEntry
{
    public function __construct(
        public string $code,
        public string $trustTier,
        public string $baseUrl,
        public bool $enabled,
        /** Null where the config entry declares none — shown as missing rather than guessed. */
        public ?int $priority,
        public bool $mirrored,
    ) {
    }

    /** @return array{code: string, trust_tier: string, base_url: string, enabled: bool, priority: int|null, mirrored: bool} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'trust_tier' => $this->trustTier,
            'base_url' => $this->baseUrl,
            'enabled' => $this->enabled,
            'priority' => $this->priority,
            'mirrored' => $this->mirrored,
        ];
    }
}
