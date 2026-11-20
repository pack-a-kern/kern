<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Actions;

use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\TaxRomania\Subjects\DataTransferObjects\RegisterPfaSubjectInput;
use Kern\TaxRomania\Subjects\Enums\FiscalIdentifierKind;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Enums\SubjectType;
use Kern\TaxRomania\Subjects\Exceptions\DuplicateRegistrationNumberException;
use Kern\TaxRomania\Subjects\Exceptions\SubjectLimitReachedException;
use Kern\TaxRomania\Subjects\Models\PfaProfile;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Subjects\Services\SubjectAllowance;

/**
 * Registers a new PFA `TaxSubject` — the extension proof `docs/handoff/034-pfa-extension.md`
 * (Milestone 12, `BLUEPRINT.md` §38.B) exists to demonstrate: this class is a file added without
 * modifying `Kern\TaxRomania\Subjects\Actions\RegisterPfSubject`, `SubjectMembership`, `TenantContext`,
 * `ScopedToTenant`, or anything under `Domain/Audit`, `Domain/Identity`, `Kern\Platform\Crypto`,
 * or `Kern\Platform\KeyManagement`.
 *
 * Deliberately structured identically to `RegisterPfSubject` — same two-write `TaxSubject`
 * creation (see that class's own docblock for exactly why), same duplicate-identifier handling
 * (an owned number refused, a reset husk reconnected — ADR 0029, {@see reattach()}), same
 * membership + audit event shape — because the whole point is that a second subject type needs a
 * second *action*, not a change to any shared mechanism.
 *
 * This is the only place in this codebase that constructs `SubjectType::PFA`.
 *
 * ## The registration number is this subject's canonical fiscal identifier (ADR 0011)
 *
 * `Kern\TaxRomania\Subjects\Models\PfaProfile` does not store the registration number at all —
 * `tax_subjects.fiscal_identifier_ciphertext`/`fiscal_identifier_blind_index` is the single
 * canonical location for every subject type's fiscal identifier
 * (`docs/adr/0011-canonical-fiscal-identifier.md`), the same column `RegisterPfSubject` populates
 * for a PF's CNP. What keeps the two from colliding is `Kern\TaxRomania\Subjects\Enums\
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
 * `Kern\Platform\Crypto\BlindIndex\Normalizer::cnp()` strips everything but digits — a rule that is
 * true of CNP specifically and would be an unverified assumption about a registration number's
 * shape. `FiscalIdentifierKind::CUI` normalises with `Normalizer::text()` instead (already used
 * for `Kern\TaxRomania\Spv\Actions\RegisterSpvConnection`'s SPV username and `Kern\TaxRomania\Wallet\
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

        $existingId = TaxSubject::query()
            ->where('fiscal_identifier_blind_index', $registrationNumberBlindIndex)->value('id');

        // Owned already → refused; a reset husk (identity scrubbed, hash kept) → reconnected, exactly
        // as RegisterPfSubject handles a freed CNP. ADR 0029; ownership decided under a lock in
        // {@see reattach()}.
        if (is_string($existingId)) {
            return $this->reattach($existingId, $input);
        }

        return DB::transaction(function () use ($input, $registrationNumberBlindIndex): TaxSubject {
            // A new taxpayer is one more in use.
            $this->allowance->ensureActiveRoom($input->registeringUserId);

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

            $this->createPfaProfile($subject->id, $input);

            SubjectMembership::grantOwnership($subject->id, $input->registeringUserId);

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

    /**
     * Reconnects a reset PFA to whoever registers its number again — the PFA counterpart of
     * {@see RegisterPfSubject::reattach()}, on the same row-locked, owner-guarded terms (ADR 0029).
     */
    private function reattach(string $subjectId, RegisterPfaSubjectInput $input): TaxSubject
    {
        return DB::transaction(function () use ($subjectId, $input): TaxSubject {
            $subject = TaxSubject::query()->whereKey($subjectId)->lockForUpdate()->firstOrFail();

            if (SubjectMembership::query()->where('subject_id', $subjectId)->whereNull('revoked_at')->exists()) {
                throw DuplicateRegistrationNumberException::alreadyRegistered();
            }

            // Reattach only ever revives a husk a reset left behind — closed, its identity scrubbed to
            // the kept hash. A subject that still holds an identity is not one, even with no active
            // membership; reviving it would hand its old accounts and documents to whoever knows the CUI.
            if ($subject->status !== SubjectStatus::CLOSED || $subject->fiscal_identifier_ciphertext !== null) {
                throw DuplicateRegistrationNumberException::alreadyRegistered();
            }

            $this->allowance->ensureActiveRoom($input->registeringUserId);

            $subject->status = SubjectStatus::ACTIVE;
            $subject->display_name_ciphertext = $input->displayName;
            $subject->fiscal_identifier_ciphertext = $input->registrationNumber;
            $subject->fiscal_identifier_kind = FiscalIdentifierKind::CUI;
            $subject->save();

            $this->createPfaProfile($subjectId, $input);

            SubjectMembership::grantOwnership($subjectId, $input->registeringUserId);

            $this->audit->record(AuditEventInput::occurringNow(
                subjectId: $subjectId,
                actorId: $input->registeringUserId,
                eventType: 'TaxSubjectReattached',
                resourceType: 'TaxSubject',
                resourceId: $subjectId,
                payloadHash: $this->audit->hashPayload(['type' => SubjectType::PFA->value]),
            ));

            return $subject;
        });
    }

    private function createPfaProfile(string $subjectId, RegisterPfaSubjectInput $input): void
    {
        PfaProfile::create([
            'tax_subject_id' => $subjectId,
            'trade_name_ciphertext' => $input->tradeName,
            'caen_code' => $input->caenCode,
            'county_code' => $input->countyCode,
            'locality_ciphertext' => $input->locality,
            'address_ciphertext' => $input->address,
        ]);
    }
}
