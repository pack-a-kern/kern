<?php

declare(strict_types=1);

namespace App\Domain\Documents\Review;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Documents\Enums\ExtractionStatus;
use App\Domain\Documents\Enums\ExtractorType;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentExtraction;
use App\Domain\Documents\Review\Exceptions\ExtractionAlreadyReviewedException;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * `BLUEPRINT.md` §14.6 — the human "this proposal is wrong, here is the correct value" transition.
 *
 * **Never overwrites `result_ciphertext` in place.** The original `PROPOSED` row is transitioned
 * to `REJECTED` (the model's exact proposed values were not accepted as-is) and its content is
 * left untouched; the corrected fields are written to a brand-new row
 * (`ExtractorType::HUMAN_CORRECTION`), immediately `ACCEPTED` under the same reviewer — a human
 * directly supplying the value *is* the review decision, so there is no separate "propose, then
 * review" step for a human's own input the way there is for a machine proposal. Both rows share
 * `document_id`, so "what did the model say, and what did the human decide instead" is always
 * reconstructable from the table alone — the audit trail this feature exists to produce.
 */
final readonly class CorrectDocumentExtraction
{
    private const string EXTRACTOR_VERSION = 'human-review-1';

    public function __construct(
        private AuditChainService $audit,
        private ConnectionResolverInterface $connections,
    ) {
    }

    /**
     * @param array<array-key, mixed> $correctedFields
     *
     * @throws ExtractionAlreadyReviewedException if `$original` is no longer `PROPOSED`.
     */
    public function __invoke(
        Document $document,
        DocumentExtraction $original,
        string $reviewerId,
        array $correctedFields,
    ): DocumentExtraction {
        if ($original->status !== ExtractionStatus::PROPOSED) {
            throw ExtractionAlreadyReviewedException::forExtraction($original->id);
        }

        return $this->connections->connection()->transaction(function () use ($document, $original, $reviewerId, $correctedFields): DocumentExtraction {
            $original->review($reviewerId, ExtractionStatus::REJECTED);

            $corrected = DocumentExtraction::propose(
                document: $document,
                extractorType: ExtractorType::HUMAN_CORRECTION,
                extractorVersion: self::EXTRACTOR_VERSION,
                modelProvider: null,
                modelName: null,
                schemaVersion: $original->schema_version,
                result: $correctedFields,
                confidenceDecimal: '1.0000',
            );
            $corrected->review($reviewerId, ExtractionStatus::ACCEPTED);

            $this->audit->record(AuditEventInput::occurringNow(
                subjectId: $document->tax_subject_id,
                actorId: $reviewerId,
                eventType: 'DocumentExtractionCorrected',
                resourceType: 'DocumentExtraction',
                resourceId: $corrected->id,
                payloadHash: $this->audit->hashPayload([
                    'document_id' => $document->id,
                    'supersedes_extraction_id' => $original->id,
                ]),
            ));

            return $corrected;
        });
    }
}
