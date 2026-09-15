<?php

declare(strict_types=1);

namespace App\Domain\Documents\Payments;

use App\Domain\Subjects\Models\TaxSubject;
use App\Support\Text\PartyName;

/**
 * Which side of a payment the taxpayer is on, and therefore who the other party is.
 *
 * This used to be the model's job, and it could not do it: the request named the taxpayer, the
 * model failed to find that name on the page, and every field defined relative to them was answered
 * relative to the document instead. A dividend decision paying its associates came back as money
 * OUT with "Alpha Bank" as the counterparty — the bank was simply the only other name available.
 *
 * It belongs here for a plainer reason than accuracy: **who the taxpayer is, is something this
 * codebase knows.** Deciding it locally is deterministic, testable, costs no tokens, and needs
 * nothing about the person to leave the machine — the identity is compared here and only the
 * conclusion travels.
 *
 * The extraction stays a record of the document: `payer` and `beneficiary` as printed, both
 * figures, the rate. What it means for one taxpayer is derived, and derived again if the document
 * is later attached to a different subject.
 */
final readonly class ResolvePaymentParties
{
    /**
     * Money coming to the taxpayer, money leaving them, or neither.
     *
     * Null when neither side matches: a dividend decision names three associates and only one of
     * them is the person filing, so the other two rows are facts about the document rather than
     * transactions anybody here can record.
     *
     * @param  array<array-key, mixed>  $payment  one entry of a reading's `payments`
     * @return array{direction: 'IN'|'OUT'|null, counterparty: string|null}
     */
    public function __invoke(TaxSubject $subject, array $payment): array
    {
        $payer = $this->text($payment, 'payer');
        $beneficiary = $this->text($payment, 'beneficiary');
        $names = $this->namesOf($subject);

        if ($this->isSubject($beneficiary, $names)) {
            return ['direction' => 'IN', 'counterparty' => $payer];
        }

        if ($this->isSubject($payer, $names)) {
            return ['direction' => 'OUT', 'counterparty' => $beneficiary];
        }

        return ['direction' => null, 'counterparty' => null];
    }

    /**
     * What this subject may be called on a document: the display name, and the fiscal identifier
     * where there is one. Compared here and, like everything else in this class, never sent anywhere.
     *
     * @return list<string>
     */
    private function namesOf(TaxSubject $subject): array
    {
        $names = [];

        foreach ([$subject->display_name_ciphertext, $subject->fiscal_identifier_ciphertext] as $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                $names[] = $value;
            }
        }

        return $names;
    }

    /**
     * Whether a party printed on the document is this taxpayer.
     *
     * The printed name is the long side and the account name the short one — "Laur" matches
     * "LAURENTIU", never the reverse. See {@see PartyName::contains()}.
     *
     * @param  list<string>  $names
     */
    private function isSubject(?string $printed, array $names): bool
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
