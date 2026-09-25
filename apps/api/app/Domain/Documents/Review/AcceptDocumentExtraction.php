<?php

declare(strict_types=1);

namespace App\Domain\Documents\Review;

use App\Domain\Documents\Enums\ExtractionStatus;
use App\Domain\Documents\Models\DocumentExtraction;
use App\Domain\Documents\Review\Exceptions\ExtractionAlreadyReviewedException;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;

/**
 * `BLUEPRINT.md` §14.6 — the human "accept this proposal exactly as given" transition. Requires the
 * extraction to still be `PROPOSED`: nothing here is permitted to move a value to `ACCEPTED`
 * except a named human reviewer, exactly `App\Domain\Documents\Models\DocumentExtraction::
 * review()`'s own contract.
 *
 * Records one audit event in addition to the `document_extractions` row's own `reviewed_by`/
 * `updated_at` columns — the row answers "who and when" for an ordinary read, the audit chain
 * answers the same question tamper-evidently, independent of that row ever being altered again.
 */
final readonly class AcceptDocumentExtraction
{
    public function __construct(private AuditChainService $audit)
    {
    }

    /**
     * @throws ExtractionAlreadyReviewedException if `$extraction` is no longer `PROPOSED`.
     */
    public function __invoke(DocumentExtraction $extraction, string $reviewerId, string $subjectId): DocumentExtraction
    {
        if ($extraction->status !== ExtractionStatus::PROPOSED) {
            throw ExtractionAlreadyReviewedException::forExtraction($extraction->id);
        }

        $extraction->review($reviewerId, ExtractionStatus::ACCEPTED);

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $subjectId,
            actorId: $reviewerId,
            eventType: 'DocumentExtractionAccepted',
            resourceType: 'DocumentExtraction',
            resourceId: $extraction->id,
            payloadHash: $this->audit->hashPayload(['document_id' => $extraction->document_id]),
        ));

        return $extraction;
    }
}
