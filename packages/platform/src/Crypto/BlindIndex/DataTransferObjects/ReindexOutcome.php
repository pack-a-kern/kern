<?php

declare(strict_types=1);

namespace Kern\Platform\Crypto\BlindIndex\DataTransferObjects;

/**
 * What one column's re-indexing did.
 *
 * `$unrecomputable` is the number that decides whether an old blind-index key may be removed from
 * the configuration: a row whose value could not be recovered keeps an index under the key it was
 * written with, and dropping that key makes the row unfindable. It is reported rather than swallowed
 * for exactly that reason — the number has to be zero before the old key goes, and a run that hid it
 * would make the configuration look safe to prune when it is not.
 */
final readonly class ReindexOutcome
{
    public function __construct(
        public string $label,
        public int $examined,
        public int $rewritten,
        public int $unrecomputable,
    ) {
    }
}
