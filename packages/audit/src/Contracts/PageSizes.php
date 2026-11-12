<?php

declare(strict_types=1);

namespace Kern\Audit\Contracts;

/**
 * How many rows a page of a list may hold — the installation's to say, not this package's.
 *
 * Declared here so the audit trail's endpoint pages like every other list without requiring the
 * package that decides it: `kern/platform` binds it from its configuration.
 */
interface PageSizes
{
    /**
     * The validation rules for a `per_page` the client may send.
     *
     * @return list<mixed>
     */
    public function rule(): array;

    /**
     * The page size to use: the one asked for, already validated by `rule()`, or the default.
     *
     * @return positive-int
     */
    public function of(mixed $requested): int;
}
