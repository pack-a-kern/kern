<?php

declare(strict_types=1);

namespace Kern\Documents\Payments;

use Kern\Platform\Tenancy\Contracts\TenantNames;
use Kern\Platform\Text\PartyName;

/**
 * Which side of a payment the tenant is on, and therefore who the other party is.
 *
 * This used to be the model's job, and it could not do it: the request named the taxpayer, the
 * model failed to find that name on the page, and every field defined relative to them was answered
 * relative to the document instead. A dividend decision paying its associates came back as money
 * OUT with "Alpha Bank" as the counterparty — the bank was simply the only other name available.
 *
 * It belongs here for a plainer reason than accuracy: **who the tenant is, is something this
 * codebase knows.** Deciding it locally is deterministic, testable, costs no tokens, and needs
 * nothing about the person to leave the machine — the identity is compared here and only the
 * conclusion travels. What the tenant is *called* comes from {@see TenantNames}, because where
 * those names are kept and how they are decrypted is the application's business.
 *
 * The extraction stays a record of the document: `payer` and `beneficiary` as printed, both
 * figures, the rate. What it means for one tenant is derived, and derived again if the document
 * is later attached to a different tenant.
 */
final readonly class ResolvePaymentParties
{
    public function __construct(private TenantNames $names)
    {
    }

    /**
     * Money coming to the tenant, money leaving them, or neither.
     *
     * Null when neither side matches: a dividend decision names three associates and only one of
     * them is the person filing, so the other two rows are facts about the document rather than
     * transactions anybody here can record.
     *
     * @param  array<array-key, mixed>  $payment  one entry of a reading's `payments`
     * @return array{direction: 'IN'|'OUT'|null, counterparty: string|null}
     */
    public function __invoke(string $tenantId, array $payment): array
    {
        $payer = $this->text($payment, 'payer');
        $beneficiary = $this->text($payment, 'beneficiary');
        $names = $this->names->namesFor($tenantId);

        if ($this->isTenant($beneficiary, $names)) {
            return ['direction' => 'IN', 'counterparty' => $payer];
        }

        if ($this->isTenant($payer, $names)) {
            return ['direction' => 'OUT', 'counterparty' => $beneficiary];
        }

        return ['direction' => null, 'counterparty' => null];
    }

    /**
     * Whether a party printed on the document is the tenant.
     *
     * The printed name is the long side and the account name the short one — "Laur" matches
     * "LAURENTIU", never the reverse. See {@see PartyName::contains()}.
     *
     * @param  list<string>  $names
     */
    private function isTenant(?string $printed, array $names): bool
    {
        if ($printed === null) {
            return false;
        }

        foreach ($names as $name) {
            if (PartyName::contains($printed, $name)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<array-key, mixed>  $payment */
    private function text(array $payment, string $key): ?string
    {
        $value = $payment[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
