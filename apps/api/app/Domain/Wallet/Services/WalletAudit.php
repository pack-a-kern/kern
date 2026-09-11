<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;

/**
 * Every change to a wallet record, in the subject's append-only hash chain (`BLUEPRINT.md` §4.8).
 *
 * One place rather than an `AuditChainService` call at each of a dozen sites, because the parts
 * that are easy to get wrong are the parts that must not vary: the resource type must match the
 * `Domain/Wallet` vocabulary, the payload must be hashed the same way every time, and — the one
 * that actually matters — **no encrypted value may reach the payload**.
 *
 * That last rule is why callers pass identifiers and amounts rather than a model. An account's
 * display name and IBAN, and a transaction's description and counterparty, are encrypted at rest
 * under the subject's DEK; hashing them into an audit payload would leak nothing directly but would
 * make the chain's contents depend on data the chain is not entitled to see. Amounts, currencies,
 * dates and enum values are stored in clear already and say what changed.
 *
 * Recording is deliberately not conditional on anything. An edit nobody audited is indistinguishable
 * from an edit that never happened, and "who changed this figure" is the question a tax record has
 * to be able to answer years later.
 */
final readonly class WalletAudit
{
    public function __construct(private AuditChainService $chain)
    {
    }

    /**
     * @param  array<string, scalar|null>  $payload  plain values only — never an encrypted field.
     */
    public function record(
        string $subjectId,
        ?string $actorId,
        string $eventType,
        string $resourceType,
        string $resourceId,
        array $payload = [],
    ): void {
        $this->chain->record(AuditEventInput::occurringNow(
            subjectId: $subjectId,
            actorId: $actorId,
            eventType: $eventType,
            resourceType: $resourceType,
            resourceId: $resourceId,
            payloadHash: $this->chain->hashPayload($payload),
        ));
    }
}
