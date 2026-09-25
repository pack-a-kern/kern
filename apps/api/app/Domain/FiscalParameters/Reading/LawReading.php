<?php

declare(strict_types=1);

namespace App\Domain\FiscalParameters\Reading;

/**
 * One reading of the law: what was read, and whether a model was reached at all.
 *
 * The two are not the same. A model that answers "the text does not say" has read the law; a
 * provider that refuses has read nothing, and a value missing for that reason is not a value the
 * law lacks.
 */
final readonly class LawReading
{
    private function __construct(
        public ?LawAnswer $answer,
        public bool $reached,
    ) {
    }

    public static function of(?LawAnswer $answer): self
    {
        return new self($answer, true);
    }

    public static function unreached(): self
    {
        return new self(null, false);
    }
}
