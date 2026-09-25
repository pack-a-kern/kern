<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Actions;

use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * Suspending, closing, or bringing a taxpayer back.
 *
 * ## There is no delete, and there cannot be one
 *
 * `audit_events.subject_id` and `audit_chain_heads.subject_id` are `ON DELETE RESTRICT`, and
 * `declarations`, `tax_facts` and `tax_assessments` are `NO ACTION`: a taxpayer that has ever done
 * anything cannot be removed, by design. Everything else — accounts, transactions, documents,
 * rental contracts, SPV messages — is `ON DELETE CASCADE`, so a delete that *did* succeed would
 * take the fiscal record with it and leave an audit chain pointing at nothing.
 *
 * Closing is what people mean when they reach for delete here: the taxpayer stops appearing in the
 * places where one is chosen, and every figure filed under it stays exactly where it is.
 */
final readonly class ChangeSubjectStatus
{
    public function __construct(private AuditChainService $audit)
    {
    }

    public function __invoke(TaxSubject $subject, SubjectStatus $status, string $actorId): TaxSubject
    {
        $previous = $subject->status;

        if ($previous === $status) {
            return $subject;
        }

        $subject->status = $status;
        $subject->save();

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $subject->id,
            actorId: $actorId,
            eventType: 'TaxSubjectStatusChanged',
            resourceType: 'TaxSubject',
            resourceId: $subject->id,
            payloadHash: $this->audit->hashPayload([
                'from' => $previous->value,
                'to' => $status->value,
            ]),
        ));

        return $subject;
    }
}
