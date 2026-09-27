<?php

declare(strict_types=1);

namespace Kern\Platform\Crypto\BlindIndex;

/**
 * Where a package registers its {@see Contracts\BlindIndexReindexer}s.
 *
 * A container tag rather than a list in one file, because the blind-indexed tables do not belong to
 * one package: `users` is this one's, the taxpayer, wallet, import and SPV columns are another's, and
 * a package added later should be able to bring its own without editing anything here. The command
 * that runs them resolves the tag and nothing else.
 */
final class BlindIndexReindexers
{
    public const string TAG = 'kern.blind-index-reindexer';

    private function __construct()
    {
    }
}
