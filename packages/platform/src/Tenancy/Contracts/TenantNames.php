<?php

declare(strict_types=1);

namespace Kern\Platform\Tenancy\Contracts;

/**
 * Every name a tenant may be written under, for code that has to recognise them on a page.
 *
 * A document names its parties in print — "SANDU LAURENTIU-CONSTANTIN", a company's registration
 * number — and deciding which of them is the tenant is what tells a payment from a receipt. The
 * comparison is {@see \Kern\Platform\Text\PartyName}'s and needs no tenant model; what it needs is
 * the list, and only the application knows where a tenant's names are kept and how they are
 * decrypted.
 *
 * An installation with no tenants answers with an empty list, and nothing is ever recognised — which
 * is the correct answer, not a failure.
 */
interface TenantNames
{
    /** @return list<string> */
    public function namesFor(string $tenantId): array;
}
