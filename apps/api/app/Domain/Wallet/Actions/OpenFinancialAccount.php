<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\DataTransferObjects\OpenFinancialAccountInput;
use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Support\Crypto\BlindIndex\BlindIndexHasher;
use App\Support\Crypto\BlindIndex\Normalizer;

/**
 * Opens one manually-entered `financial_accounts` row (`BLUEPRINT.md` §34: no bank-API sync — every
 * account this milestone can create is `RecordSource::MANUAL`).
 */
final readonly class OpenFinancialAccount
{
    public function __construct(
        private BlindIndexHasher $blindIndex,
    ) {
    }

    public function __invoke(OpenFinancialAccountInput $input): FinancialAccount
    {
        $account = new FinancialAccount([
            'tax_subject_id' => $input->taxSubjectId,
            'kind' => $input->kind,
            'institution_name' => $input->institutionName,
            'display_name_ciphertext' => $input->displayName,
            'currency_code' => strtoupper($input->currencyCode),
            'opened_at' => $input->openedAt,
            'closed_at' => $input->closedAt,
            'source' => RecordSource::MANUAL,
            'metadata_ciphertext' => $input->metadataJson,
        ]);

        if ($input->iban !== null) {
            $account->iban_ciphertext = $input->iban;
            $account->iban_blind_index = $this->blindIndex->compute(Normalizer::iban($input->iban));
        }

        $account->save();

        return $account;
    }
}
