<?php

declare(strict_types=1);

namespace Kern\Legislation\Requirements;

/**
 * What is ready (ADR 0027): every declared requirement resolved to its parts' statuses, rolled up to
 * `is_bootstrapped` — every `required` requirement met — and, per feature, whether it is `available`.
 *
 * Each part is decided by the lookup that already exists, through {@see NeedResolvers}, so this adds
 * no new notion of "present": it asks the resolvers and reports. The parts are reported one by one,
 * so the setup screen can say which step a person is on rather than one opaque "not ready" — nothing
 * is computed in the screen (`docs/UI-PATTERNS.md`): the server says what is ready and what is next.
 */
final readonly class ReadinessReport
{
    public function __construct(
        private LegislationRequirements $requirements,
        private NeedResolvers $resolvers,
    ) {
    }

    /**
     * @return array{
     *     is_bootstrapped: bool,
     *     requirements: list<array{key: string, declared_by: string, tier: string, feature: ?string, met: bool, needs: list<array{kind: string, argument: string, met: bool}>}>,
     *     features: array<string, array{available: bool}>,
     * }
     */
    public function __invoke(): array
    {
        $isBootstrapped = true;
        /** @var array<string, array{available: bool}> $features */
        $features = [];
        $requirements = [];

        foreach ($this->requirements->all() as $requirement) {
            $parts = array_map(
                fn (RequirementNeed $need): array => [
                    'kind' => $need->kind,
                    'argument' => $need->argument,
                    'met' => $this->resolvers->isMet($need),
                ],
                $requirement->needs,
            );

            $met = array_reduce($parts, static fn (bool $all, array $part): bool => $all && $part['met'], true);

            if ($requirement->tier === RequirementTier::REQUIRED) {
                $isBootstrapped = $isBootstrapped && $met;
            }

            // A feature is available only when every requirement that gates it is met.
            if ($requirement->tier === RequirementTier::FEATURE && $requirement->feature !== null) {
                $features[$requirement->feature] = [
                    'available' => ($features[$requirement->feature]['available'] ?? true) && $met,
                ];
            }

            $requirements[] = [
                'key' => $requirement->key,
                'declared_by' => $requirement->declaredBy,
                'tier' => $requirement->tier->value,
                'feature' => $requirement->feature,
                'met' => $met,
                'needs' => $parts,
            ];
        }

        return [
            'is_bootstrapped' => $isBootstrapped,
            'requirements' => $requirements,
            'features' => $features,
        ];
    }
}
