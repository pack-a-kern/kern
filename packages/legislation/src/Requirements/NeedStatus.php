<?php

declare(strict_types=1);

namespace Kern\Legislation\Requirements;

/**
 * A requirement need resolved (ADR 0027): whether it is met, and an optional `detail` the owning
 * package attaches so the setup screen can say which step a person is on and name what it is waiting
 * for — a value proposed and awaiting approval, a run still fetching — without the screen computing
 * anything (`docs/UI-PATTERNS.md`).
 *
 * `kern/legislation` carries the detail through {@see ReadinessReport} unread; the package that
 * resolves the need decides the detail's shape, the way it decides the need itself through
 * {@see NeedResolvers}. A resolver that only answers met/unmet returns a bare `bool` and this wraps
 * it with no detail.
 */
final readonly class NeedStatus
{
    /** @param array<string, mixed>|null $detail */
    public function __construct(
        public bool $met,
        public ?array $detail = null,
    ) {
    }
}
