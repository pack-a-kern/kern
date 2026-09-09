<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\DataTransferObjects\RecordAccountBalanceSnapshotInput;
use App\Domain\Wallet\Models\AccountBalanceSnapshot;

final class RecordAccountBalanceSnapshot
{
    public function __invoke(RecordAccountBalanceSnapshotInput $input): AccountBalanceSnapshot
    {
        return AccountBalanceSnapshot::create([
            'tax_subject_id' => $input->taxSubjectId,
            'account_id' => $input->accountId,
            'balance_minor' => $input->balanceMinor,
            'as_of' => $input->asOf,
            'source' => $input->source,
            'is_verified' => $input->isVerified,
        ]);
    }
}
