<?php

declare(strict_types=1);

namespace Kern\Legislation\Requirements;

use RuntimeException;

/**
 * A requirement a package declares (`legislation.requirements.declared.{key}`), checked when read:
 * the values come from that package's configuration, so one missing or malformed fails here, naming
 * the requirement and what is wrong — its variable is in the declaring package's `.env.example`
 * (ADR 0017), as a watch's is (ADR 0026).
 */
final readonly class RequirementDefinition
{
    /** @param list<RequirementNeed> $needs */
    public function __construct(
        public string $key,
        public string $declaredBy,
        public RequirementTier $tier,
        public ?string $feature,
        public array $needs,
    ) {
    }

    /** @param array<array-key, mixed> $entry */
    public static function fromConfig(string $key, array $entry): self
    {
        $by = is_string($entry['declared_by'] ?? null) ? $entry['declared_by'] : 'a package';
        $fail = static fn (string $what): never => throw new RuntimeException("The legislation requirement \"{$key}\" ({$by}): {$what} — see that package's .env.example.");

        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $key) !== 1) {
            $fail('the key must be a few words of a-z, digits, dots');
        }
        if (! is_string($entry['declared_by'] ?? null) || preg_match('/\A[a-z0-9\/_-]{1,64}\z/', $entry['declared_by']) !== 1) {
            $fail('declared_by is missing or malformed');
        }

        $tier = RequirementTier::tryFrom(is_string($entry['tier'] ?? null) ? $entry['tier'] : '')
            ?? $fail('tier must be required, feature or optional');

        $feature = $entry['feature'] ?? null;
        if ($tier === RequirementTier::FEATURE && (! is_string($feature) || preg_match('/\A[a-z0-9_.-]{1,64}\z/', $feature) !== 1)) {
            $fail('a feature requirement names the feature it gates, as a few words of a-z, digits, dots');
        }
        if ($tier !== RequirementTier::FEATURE && $feature !== null) {
            $fail('only a feature requirement names a feature');
        }

        $declared = $entry['needs'] ?? null;
        if (! is_array($declared) || $declared === []) {
            $fail('needs lists at least one part the requirement rests on');
        }

        $needs = [];
        foreach ($declared as $kind => $argument) {
            if (! is_string($kind) || preg_match('/\A[a-z0-9_.-]{1,64}\z/', $kind) !== 1) {
                $fail('each need is named by its kind — law, parameters or watch');
            }
            if (! is_string($argument) || trim($argument) === '') {
                $fail("the need \"{$kind}\" has no argument");
            }
            $needs[] = new RequirementNeed($kind, $argument);
        }

        return new self(
            key: $key,
            declaredBy: $entry['declared_by'],
            tier: $tier,
            feature: is_string($feature) ? $feature : null,
            needs: $needs,
        );
    }
}
