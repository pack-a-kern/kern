<?php

declare(strict_types=1);

namespace Kern\Legislation\Requirements;

/**
 * One part of a requirement's `needs` (ADR 0027): a kind — the lookup that decides it — and the
 * argument that lookup is given. `law` → a law identifier, `parameters` → `active-year`,
 * `watch` → a declared watch's key.
 *
 * The kind is resolved by whichever {@see NeedResolvers} entry a package registered for it, so this
 * package names no table, model or query of another: it carries the pair and asks the resolver.
 */
final readonly class RequirementNeed
{
    public function __construct(
        public string $kind,
        public string $argument,
    ) {
    }
}
