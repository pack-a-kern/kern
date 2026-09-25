<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters\Reading;

/** What a reader made of the law: the numbers and the paragraph of the law that states them. */
final readonly class LawAnswer
{
    /** @param list<int> $values */
    public function __construct(
        public array $values,
        public string $sectionId,
        public string $citation,
        /** The paragraph the second number stands in, where a value has two ends in two places. */
        public string $citationOfLast,
        public string $model,
        /** The two ends of one base, rather than a value of its own for each level of income. */
        public bool $isRange = false,
    ) {
    }

    /** Whether another reader made the same of it — the same numbers in the same paragraph. */
    public function agreesWith(?self $other): bool
    {
        return $other !== null && $other->values === $this->values && $other->sectionId === $this->sectionId;
    }
}
