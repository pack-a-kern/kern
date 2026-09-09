<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Support\KeyManagement\Contracts\SubjectDekRepository;
use App\Support\KeyManagement\ValueObjects\WrappedDataKey;
use RuntimeException;

/**
 * In-memory stand-in for the `tax_subjects.encrypted_dek`/`dek_kek_version` columns
 * (docs/handoff/005-crypto-core.md "Interfaces exposed to other modules") — no `tax_subjects`
 * table exists yet. Enforces the same create-only contract the real implementation must:
 * `save()` throws if a wrapped DEK already exists for the subject.
 *
 * Lives under tests/, not app/Support — a test double, not a production driver.
 */
final class InMemorySubjectDekRepository implements SubjectDekRepository
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
