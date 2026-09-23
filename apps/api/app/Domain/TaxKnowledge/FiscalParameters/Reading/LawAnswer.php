<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Reading;

/** What a reader made of the law: the numbers, the paragraph they stand in, and the sentence quoted. */
final readonly class LawAnswer
{
    /** @param list<int> $values */
    public function __construct(
        public array $values,
        public string $sectionId,
        public string $citation,
        public string $quote,
        public string $model,
    ) {
    }

    /** Whether another reader made the same of it — the same numbers in the same paragraph. */
    public function agreesWith(?self $other): bool
    {
        return $other !== null && $other->values === $this->values && $other->sectionId === $this->sectionId;
    }
}
