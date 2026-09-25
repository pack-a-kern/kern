<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\FiscalParameters;

use App\Domain\TaxKnowledge\FiscalParameters\FiscalParameterDecisionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;

/**
 * What every decision on a fiscal value shares: who made it, recorded in the audit chain — the chain
 * says which person decided what a tax is computed from, and cannot be edited afterwards — and a
 * refusal answered with its reason, for the screen to say in the reader's language.
 */
trait RecordsFiscalParameterDecision
{
    /** The person deciding. Every route here is behind authentication, so there is one. */
    private function decidedBy(Request $request): string
    {
        $key = $request->user()?->getAuthIdentifier();

        abort_unless(is_string($key), 403);

        return $key;
    }

    /** @param array<string, mixed> $payload */
    private function audit(AuditChainService $audit, string $actorId, string $eventType, string $resourceType, string $resourceId, array $payload): void
    {
        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $actorId,
            eventType: $eventType,
            resourceType: $resourceType,
            resourceId: $resourceId,
            payloadHash: $audit->hashPayload($payload),
        ));
    }

    private function refused(FiscalParameterDecisionException $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage(), 'reason' => $exception->reason], 422);
    }
}
