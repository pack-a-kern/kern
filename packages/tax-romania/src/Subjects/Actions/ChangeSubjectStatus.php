<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Actions;

use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Exceptions\TooManyActiveSubjectsException;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Subjects\Services\SubjectAllowance;

/**
 * Closing a taxpayer, or bringing it back.
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
    public function __construct(private AuditChainService $audit, private SubjectAllowance $allowance)
    {
    }

    /**
     * @param string $actorId an owner of `$subject`: the one whose taxpayers in use a reactivation counts against
     *
     * @throws TooManyActiveSubjectsException reactivating past `SUBJECTS_MAX_ACTIVE`
     */
    public function __invoke(TaxSubject $subject, SubjectStatus $status, string $actorId): TaxSubject
    {
        $previous = $subject->status;

        if ($previous === $status) {
            return $subject;
        }

        DB::transaction(function () use ($subject, $status, $actorId): void {
            if ($status === SubjectStatus::ACTIVE) {
                $this->allowance->ensureActiveRoom($actorId);
            }

            $subject->status = $status;
            $subject->save();
        });

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
