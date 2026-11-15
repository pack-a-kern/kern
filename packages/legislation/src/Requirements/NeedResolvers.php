<?php

declare(strict_types=1);

namespace Kern\Legislation\Requirements;

use Closure;
use RuntimeException;

/**
 * How each kind of requirement need is decided (ADR 0027).
 *
 * A need's kind — `law`, `parameters`, `watch` — is resolved by a closure the owning package
 * registered from its provider, the way a law's dependents are counted through
 * {@see \Kern\Legislation\Dependents\DocumentDependents}. So this package decides `law` and `watch`
 * (its own domain) and never learns what a fiscal parameter is: the Romanian tax package registers
 * `parameters` and resolves it against its own models.
 *
 * A need whose kind no package registered is a declaration error, so resolving it fails loudly
 * rather than being read as "met" or "unmet".
 */
final class NeedResolvers
{
    /** @var array<string, Closure(string): (bool|NeedStatus)> */
    private array $resolvers = [];

    /**
     * "This is how a need of this kind is decided" — from the owning package's provider.
     *
     * The closure returns whether the need with this argument is satisfied, as a bare `bool`, or a
     * {@see NeedStatus} when it also carries detail for the setup screen (what it is waiting for).
     *
     * @param Closure(string): (bool|NeedStatus) $resolve
     */
    public function register(string $kind, Closure $resolve): void
    {
        $this->resolvers[$kind] = $resolve;
    }

    public function knows(string $kind): bool
    {
        return isset($this->resolvers[$kind]);
    }

    public function status(RequirementNeed $need): NeedStatus
    {
        $resolve = $this->resolvers[$need->kind]
            ?? throw new RuntimeException("No resolver is registered for the requirement need \"{$need->kind}\".");

        $result = $resolve($need->argument);

        return $result instanceof NeedStatus ? $result : new NeedStatus($result);
    }

    public function isMet(RequirementNeed $need): bool
    {
        return $this->status($need)->met;
    }
}
