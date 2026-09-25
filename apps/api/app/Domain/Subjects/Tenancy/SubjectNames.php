<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Tenancy;

use App\Domain\Subjects\Models\TaxSubject;
use Kern\Platform\Tenancy\Contracts\TenantNames;

/**
 * What a taxpayer may be called on a document: their display name, and their fiscal identifier.
 *
 * The platform's answer to "is this printed party the tenant?" needs a list of names and nothing
 * else; where those names live and how they are decrypted is this module's business, which is why
 * the contract is there and the query is here.
 *
 * Both values are decrypted by the model's casts and never leave the process — the comparison
 * happens locally and only its conclusion travels (see `Kern\Documents\Payments\
 * ResolvePaymentParties`).
 */
final readonly class SubjectNames implements TenantNames
{
    /** @return list<string> */
    public function namesFor(string $tenantId): array
    {
        $subject = TaxSubject::query()->withoutGlobalScopes()->find($tenantId);

        if ($subject === null) {
            return [];
        }

        $names = [];

        foreach ([$subject->display_name_ciphertext, $subject->fiscal_identifier_ciphertext] as $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                $names[] = $value;
            }
        }

        return $names;
    }
}
