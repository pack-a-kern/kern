<?php

declare(strict_types=1);

namespace Kern\Legislation\Requirements;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The legislation a package requires — `legislation.requirements.declared`, merged from each
 * package's `config/legislation_requirements.php` the way watches are (ADR 0026) — read once into
 * typed definitions, so the readiness query and the screen take {@see RequirementDefinition}s and
 * never the raw configuration (ADR 0027).
 *
 * This package declares none of its own: it owns the registry and reads what the other packages
 * merge, exactly as it owns the watch machinery but names no watch.
 */
final readonly class LegislationRequirements
{
    /** @var list<RequirementDefinition> */
    public array $definitions;

    public function __construct(ConfigRepository $config)
    {
        $declared = $config->get('legislation.requirements.declared', []);
        $declared = is_array($declared) ? $declared : [];

        $this->definitions = array_map(
            static fn (mixed $entry, string|int $key): RequirementDefinition => RequirementDefinition::fromConfig((string) $key, is_array($entry) ? $entry : []),
            array_values($declared),
            array_keys($declared),
        );
    }

    /** @return list<RequirementDefinition> in the order the packages declare them */
    public function all(): array
    {
        return $this->definitions;
    }
}
