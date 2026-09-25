<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Actions;

use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\TaxRomania\Subjects\DataTransferObjects\UpdatePfSubjectInput;
use Kern\TaxRomania\Subjects\Models\PfProfile;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * Correcting a taxpayer's details.
 *
 * ## What cannot be changed here, and why
 *
 * The **CNP** is absent on purpose. It is not a detail about the taxpayer, it *is* the taxpayer:
 * it carries the blind index that detects duplicates, and every encrypted field is bound to this
 * subject's key. Editing it would not correct a record, it would silently turn one person's file
 * into another's. A CNP typed wrong is a subject registered wrong, and the answer to that is a new
 * registration and an archive, both of which leave a trail.
 *
 * The **country** is absent for the reason it is absent from registration: this product files
 * Romanian returns.
 *
 * Everything here is encrypted on assignment, so the audit payload hashes field *names* — which
 * fields were corrected, never what they were corrected to.
 */
final readonly class UpdatePfSubject
{
    public function __construct(private AuditChainService $audit)
    {
    }

    public function __invoke(TaxSubject $subject, UpdatePfSubjectInput $input, string $actorId): TaxSubject
    {
        return DB::transaction(function () use ($subject, $input, $actorId): TaxSubject {
            $changed = [];

            if ($input->displayName !== null) {
                $subject->display_name_ciphertext = $input->displayName;
                $changed[] = 'display_name';
            }

            $subject->save();

            /** @var PfProfile|null $profile */
            $profile = PfProfile::query()->where('tax_subject_id', $subject->id)->first();

            if ($profile instanceof PfProfile) {
                foreach ([
                    'first_name_ciphertext' => $input->firstName,
                    'last_name_ciphertext' => $input->lastName,
                    'locality_ciphertext' => $input->locality,
                    'address_ciphertext' => $input->address,
                ] as $column => $value) {
                    if ($value !== null) {
                        $profile->{$column} = $value;
                        $changed[] = $column;
                    }
                }

                if ($input->countyCode !== null) {
                    $profile->county_code = $input->countyCode;
                    $changed[] = 'county_code';
                }

                $profile->save();
            }

            $this->audit->record(AuditEventInput::occurringNow(
                subjectId: $subject->id,
                actorId: $actorId,
                eventType: 'TaxSubjectUpdated',
                resourceType: 'TaxSubject',
                resourceId: $subject->id,
                // Names, never values: this chain is readable by anyone who can read the audit
                // screen, and the values are encrypted precisely so that it is not.
                payloadHash: $this->audit->hashPayload(['fields' => $changed]),
            ));

            return $subject;
        });
    }
}
