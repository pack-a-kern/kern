<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\DataTransferObjects;

use Kern\Platform\Identity\Enums\UserStatus;

/**
 * Which page of the administrators' directory to read, and which users it holds.
 *
 * `email` is a whole address, found through its blind index: an address is encrypted, so part of
 * one cannot be looked for without decrypting every account.
 */
final readonly class UserDirectoryFilter
{
    /**
     * @param positive-int $page
     * @param positive-int $perPage
     */
    public function __construct(
        public ?string $email,
        public ?UserStatus $status,
        public ?string $role,
        public int $page,
        public int $perPage,
    ) {
    }
}
