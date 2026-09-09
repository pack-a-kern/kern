<?php

declare(strict_types=1);

namespace App\Domain\Wallet\DataTransferObjects;

use App\Domain\Wallet\Enums\RecordSource;
use DateTimeImmutable;

final readonly class RecordAccountBalanceSnapshotInput
{
    public function __construct(
        public string $taxSubjectId,
        public string $accountId,
        public int $balanceMinor,
        public DateTimeImmutable $asOf,
        public RecordSource $source = RecordSource::MANUAL,
        public bool $isVerified = false,
    ) {
    }
}
