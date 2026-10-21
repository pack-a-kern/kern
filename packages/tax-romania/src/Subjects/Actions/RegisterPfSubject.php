<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Actions;

use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\TaxRomania\Subjects\DataTransferObjects\RegisterPfSubjectInput;
use Kern\TaxRomania\Subjects\Enums\FiscalIdentifierKind;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Enums\SubjectType;
use Kern\TaxRomania\Subjects\Exceptions\DuplicateCnpException;
use Kern\TaxRomania\Subjects\Exceptions\SubjectLimitReachedException;
use Kern\TaxRomania\Subjects\Models\PfProfile;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Subjects\Services\SubjectAllowance;

/**
 * Registers a new PF `TaxSubject`: checks for a duplicate CNP across the whole system
 * (`BLUEPRINT.md` §4.1), creates the subject, its `PfProfile`, and an `OWNER` membership for the
 * registering user, and records one audit event — the worked example
 * `docs/handoff/011-identity-tenancy.md` points to for "how to append an audit event from your
 * module" (`docs/handoff/006-audit-chain.md`).
 *
 * This is the only place in this task's code that constructs `SubjectType::PF` — per `BLUEPRINT.md`
 * §2.2, no other subject type has any registration logic yet.
 *
 * ## Why `TaxSubject` is created in two writes, not one
 *
 * `TaxSubject::encryptionSubjectId()` returns its own primary key — encrypting its
 * `display_name_ciphertext`/`fiscal_identifier_ciphertext` requires that subject's own DEK to
 * already exist, and `Kern\Platform\KeyManagement\SubjectDekService`'s first-use path
 * (`Kern\TaxRomania\Subjects\Infrastructure\EloquentSubjectDekRepository`) needs the `tax_subjects`
 * row to already exist in the database before it can record a wrapped DEK against it. So: insert
 * the bare row first (no encrypted columns set — they stay `NULL`), then assign the encrypted
 * columns and save again, which is the exact write that triggers first-use DEK creation. Both
 * writes are inside one DB transaction, so a failure between them leaves no half-created subject.
 * See the `tax_subjects` migration's own docblock for the same explanation from the schema side.
 *
 * ## The CNP lives only on `tax_subjects` (ADR 0011)
 *
 * `Kern\TaxRomania\Subjects\Models\PfProfile` no longer stores `cnp_ciphertext`/`cnp_blind_index` — an
 * earlier version of this codebase stored the CNP's blind index in both `pf_profiles` and
 * `tax_subjects`, which `docs/reference/pf-coupling-audit.md` Finding 3 flagged as exactly the
 * kind of duplicated invariant `docs/CODE-QUALITY.md` §1 calls a defect. ADR 0011
 * (`docs/adr/0011-canonical-fiscal-identifier.md`) makes `tax_subjects.fiscal_identifier_ciphertext`/
 * `fiscal_identifier_blind_index` the single canonical location, tagged
 * `FiscalIdentifierKind::CNP` — the same column `RegisterPfaSubject` now populates for a PFA's
 * business identifier, tagged `FiscalIdentifierKind::CUI`, which is what makes duplicate detection
 * safe across both without a CNP and an unrelated business identifier ever colliding.
 *
 * ## Why the duplicate-CNP check is not itself tenant-scoped
 *
 * `TaxSubject::query()` here runs before any `TenantContext` is even bound (this action is what
 * *creates* the first membership a user could ever authorize against) — checking across the whole
 * table is inherent to what "duplicate CNP across the whole system" means, not a bypass of
 * anything (`docs/adr/0002-envelope-encryption.md`: this is one of the two use cases the global
 * blind-index key exists for). `TaxSubject` does not use `Kern\TaxRomania\Subjects\Tenancy\
 * ScopedToTenant`, so no escape hatch is even needed here.
 */
final readonly class RegisterPfSubject
{
    public function __construct(
        private BlindIndexHasher $blindIndex,
        private AuditChainService $audit,
        private SubjectAllowance $allowance,
    ) {
    }

    public function __invoke(RegisterPfSubjectInput $input): TaxSubject
    {
        // Before the CNP is even hashed: an account that may not register another taxpayer gets
        // the same answer whatever it typed, and checking here rather than in the controller means
        // every caller is held to it, not only the one HTTP route.
        if (! $this->allowance->hasRoom($input->registeringUserId, $input->registeringUserRoles)) {
            throw SubjectLimitReachedException::of(
                $this->allowance->limitFor($input->registeringUserRoles),
            );
        }

        $cnpBlindIndex = $this->blindIndex->compute(FiscalIdentifierKind::CNP->keyedValue($input->cnp));

        if (TaxSubject::query()->where('fiscal_identifier_blind_index', $cnpBlindIndex)->exists()) {
            throw DuplicateCnpException::alreadyRegistered();
        }

        return DB::transaction(function () use ($input, $cnpBlindIndex): TaxSubject {
            $subject = new TaxSubject();
            $subject->type = SubjectType::PF;
            $subject->country_code = $this->country();
            $subject->status = SubjectStatus::ACTIVE;
            $subject->save();

            // Second write: assigning these triggers Casts\Encrypted, which — since $subject's
            // row now exists — successfully creates $subject's DEK on first use.
            $subject->display_name_ciphertext = $input->displayName;
            $subject->fiscal_identifier_ciphertext = $input->cnp;
            $subject->fiscal_identifier_blind_index = $cnpBlindIndex;
            $subject->fiscal_identifier_kind = FiscalIdentifierKind::CNP;
            $subject->save();

            PfProfile::create([
                'tax_subject_id' => $subject->id,
                'first_name_ciphertext' => $input->firstName,
                'last_name_ciphertext' => $input->lastName,
                'birth_date_ciphertext' => $input->birthDate,
                'tax_residency_country' => $this->country(),
                'county_code' => $input->countyCode,
                'locality_ciphertext' => $input->locality,
                'address_ciphertext' => $input->address,
                'anaf_tax_office_code' => $input->anafTaxOfficeCode,
            ]);

            SubjectMembership::grantOwnership($subject->id, $input->registeringUserId);

            $this->audit->record(AuditEventInput::occurringNow(
                subjectId: $subject->id,
                actorId: $input->registeringUserId,
                eventType: 'TaxSubjectRegistered',
                resourceType: 'TaxSubject',
                resourceId: $subject->id,
                payloadHash: $this->audit->hashPayload(['type' => SubjectType::PF->value]),
            ));

            return $subject;
        });
    }

    /**
     * The country every taxpayer here belongs to.
     *
     * Configured rather than written into this method: the value is the same for every registration
     * this product performs, but "the same everywhere" and "unchangeable" are different claims, and
     * only one of them belongs in a domain action.
     */
    private function country(): string
    {
        $configured = config('subjects.default_country');

        return is_string($configured) && $configured !== '' ? $configured : 'RO';
    }
}
