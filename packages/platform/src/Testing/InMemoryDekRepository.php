<?php

declare(strict_types=1);

namespace Kern\Platform\Testing;

use Kern\Platform\KeyManagement\Contracts\SubjectDekRepository;
use Kern\Platform\KeyManagement\ValueObjects\WrappedDataKey;
use RuntimeException;

/**
 * Where a tenant's wrapped data key lives, for an installation that has no tenants table.
 *
 * One of the two things `kern/platform` asks an application for. It enforces the same create-only
 * contract a real implementation must: `save()` refuses to replace a key that already exists, because
 * replacing one silently makes every row encrypted under the old one unreadable.
 */
final class InMemoryDekRepository implements SubjectDekRepository
{
    /** @var array<string, WrappedDataKey> */
    private array $wrapped = [];

    public function find(string $subjectId): ?WrappedDataKey
    {
        return $this->wrapped[$subjectId] ?? null;
    }

    public function save(string $subjectId, WrappedDataKey $wrapped): void
    {
        if (isset($this->wrapped[$subjectId])) {
            throw new RuntimeException(sprintf('Subject "%s" already has a wrapped DEK.', $subjectId));
        }

        $this->wrapped[$subjectId] = $wrapped;
    }
}
