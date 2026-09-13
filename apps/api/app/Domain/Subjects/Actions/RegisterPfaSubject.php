<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Actions;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Subjects\DataTransferObjects\RegisterPfaSubjectInput;
use App\Domain\Subjects\Enums\FiscalIdentifierKind;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Enums\SubjectStatus;
use App\Domain\Subjects\Enums\SubjectType;
use App\Domain\Subjects\Exceptions\DuplicateRegistrationNumberException;
use App\Domain\Subjects\Exceptions\SubjectLimitReachedException;
use App\Domain\Subjects\Models\PfaProfile;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Subjects\Services\SubjectAllowance;
use App\Support\Crypto\BlindIndex\BlindIndexHasher;
use Illuminate\Support\Facades\DB;

/**
 * Registers a new PFA `TaxSubject` — the extension proof `docs/handoff/034-pfa-extension.md`
 * (Milestone 12, `BLUEPRINT.md` §38.B) exists to demonstrate: this class is a file added without
 * modifying `App\Domain\Subjects\Actions\RegisterPfSubject`, `SubjectMembership`, `TenantContext`,
 * `ScopedToTaxSubject`, or anything under `Domain/Audit`, `Domain/Identity`, `App\Support\Crypto`,
 * or `App\Support\KeyManagement`.
 *
 * Deliberately structured identically to `RegisterPfSubject` — same two-write `TaxSubject`
 * creation (see that class's own docblock for exactly why), same duplicate-identifier check
 * before the transaction, same membership + audit event shape — because the whole point is that
 * a second subject type needs a second *action*, not a change to any shared mechanism.
 *
 * This is the only place in this codebase that constructs `SubjectType::PFA`.
 *
 * ## The registration number is this subject's canonical fiscal identifier (ADR 0011)
 *
 * `App\Domain\Subjects\Models\PfaProfile` does not store the registration number at all —
 * `tax_subjects.fiscal_identifier_ciphertext`/`fiscal_identifier_blind_index` is the single
 * canonical location for every subject type's fiscal identifier
 * (`docs/adr/0011-canonical-fiscal-identifier.md`), the same column `RegisterPfSubject` populates
 * for a PF's CNP. What keeps the two from colliding is `App\Domain\Subjects\Enums\
 * FiscalIdentifierKind::CUI->keyedValue()`: it binds the literal string `"CUI:"` into the value
 * before it is normalised and hashed, so a PFA registration number and an unrelated PF's CNP that
 * happened to normalise to the same digits can never produce the same blind index. An earlier
 * version of this action stored the registration number's blind index in `pfa_profiles` instead,
 * specifically to *avoid* that collision risk by not sharing the column at all
 * (`docs/reference/pf-coupling-audit.md` Finding 2) — the kind-binding above removes the risk at
 * its root, so the shared column can now be genuinely shared.
 *
 * ## Why the registration-number normalisation differs from CNP's
 *
 * `App\Support\Crypto\BlindIndex\Normalizer::cnp()` strips everything but digits — a rule that is
 * true of CNP specifically and would be an unverified assumption about a registration number's
 * shape. `FiscalIdentifierKind::CUI` normalises with `Normalizer::text()` instead (already used
 * for `App\Domain\Spv\Actions\RegisterSpvConnection`'s SPV username and `App\Domain\Wallet\
 * Actions\RecordFinancialTransaction`'s external id — both free-form identifier strings with no
 * fixed shape), the existing, generic precedent for exactly this case, rather than inventing a
 * third normalisation rule.
 */
final readonly class RegisterPfaSubject
{
    public function __construct(
        private BlindIndexHasher $blindIndex,
        private AuditChainService $audit,
        private SubjectAllowance $allowance,
    ) {
    }

    public function __invoke(RegisterPfaSubjectInput $input): TaxSubject
    {
        // A PFA is a taxpayer like any other and counts against the same allowance — a cap that
        // only one registration path respects is not a cap.
        if (! $this->allowance->hasRoom($input->registeringUserId, $input->registeringUserRoles)) {
            throw SubjectLimitReachedException::of(
                $this->allowance->limitFor($input->registeringUserRoles),
            );
        }

        $registrationNumberBlindIndex = $this->blindIndex->compute(
            FiscalIdentifierKind::CUI->keyedValue($input->registrationNumber),
        );

        if (TaxSubject::query()->where('fiscal_identifier_blind_index', $registrationNumberBlindIndex)->exists()) {
            throw DuplicateRegistrationNumberException::alreadyRegistered();
        }

        return DB::transaction(function () use ($input, $registrationNumberBlindIndex): TaxSubject {
            $subject = new TaxSubject();
            $subject->type = SubjectType::PFA;
            $subject->country_code = 'RO';
            $subject->status = SubjectStatus::ACTIVE;
            $subject->save();

            // Second write: assigning these triggers Casts\Encrypted, which — since $subject's
            // row now exists — successfully creates $subject's DEK on first use. Mirrors
            // RegisterPfSubject's own second write, tagged FiscalIdentifierKind::CUI instead of
            // ::CNP — see this action's own docblock and ADR 0011 for why this is now safe to
            // share with PF's CNP under one column.
            $subject->display_name_ciphertext = $input->displayName;
            $subject->fiscal_identifier_ciphertext = $input->registrationNumber;
            $subject->fiscal_identifier_blind_index = $registrationNumberBlindIndex;
            $subject->fiscal_identifier_kind = FiscalIdentifierKind::CUI;
            $subject->save();

            PfaProfile::create([
                'tax_subject_id' => $subject->id,
                'trade_name_ciphertext' => $input->tradeName,
                'caen_code' => $input->caenCode,
                'county_code' => $input->countyCode,
                'locality_ciphertext' => $input->locality,
                'address_ciphertext' => $input->address,
            ]);

            SubjectMembership::create([
                'subject_id' => $subject->id,
                'user_id' => $input->registeringUserId,
                'role' => MembershipRole::OWNER,
                'permissions_json' => [],
                'valid_from' => now('UTC'),
                'revoked_at' => null,
            ]);

            $this->audit->record(AuditEventInput::occurringNow(
                subjectId: $subject->id,
                actorId: $input->registeringUserId,
                eventType: 'TaxSubjectRegistered',
                resourceType: 'TaxSubject',
                resourceId: $subject->id,
                payloadHash: $this->audit->hashPayload(['type' => SubjectType::PFA->value]),
            ));

            return $subject;
        });
    }
}
