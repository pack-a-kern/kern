<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Actions;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Subjects\DataTransferObjects\RegisterPfSubjectInput;
use App\Domain\Subjects\Enums\FiscalIdentifierKind;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Enums\SubjectStatus;
use App\Domain\Subjects\Enums\SubjectType;
use App\Domain\Subjects\Exceptions\DuplicateCnpException;
use App\Domain\Subjects\Models\PfProfile;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Support\Crypto\BlindIndex\BlindIndexHasher;
use Illuminate\Support\Facades\DB;

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
 * already exist, and `App\Support\KeyManagement\SubjectDekService`'s first-use path
 * (`App\Domain\Subjects\Infrastructure\EloquentSubjectDekRepository`) needs the `tax_subjects`
 * row to already exist in the database before it can record a wrapped DEK against it. So: insert
 * the bare row first (no encrypted columns set — they stay `NULL`), then assign the encrypted
 * columns and save again, which is the exact write that triggers first-use DEK creation. Both
 * writes are inside one DB transaction, so a failure between them leaves no half-created subject.
 * See the `tax_subjects` migration's own docblock for the same explanation from the schema side.
 *
 * ## The CNP lives only on `tax_subjects` (ADR 0011)
 *
 * `App\Domain\Subjects\Models\PfProfile` no longer stores `cnp_ciphertext`/`cnp_blind_index` — an
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
 * blind-index key exists for). `TaxSubject` does not use `App\Domain\Subjects\Tenancy\
 * ScopedToTaxSubject`, so no escape hatch is even needed here.
 */
final readonly class RegisterPfSubject
{
    public function __construct(
        private BlindIndexHasher $blindIndex,
        private AuditChainService $audit,
    ) {
    }

    public function __invoke(RegisterPfSubjectInput $input): TaxSubject
    {
        $cnpBlindIndex = $this->blindIndex->compute(FiscalIdentifierKind::CNP->keyedValue($input->cnp));

        if (TaxSubject::query()->where('fiscal_identifier_blind_index', $cnpBlindIndex)->exists()) {
            throw DuplicateCnpException::alreadyRegistered();
        }

        return DB::transaction(function () use ($input, $cnpBlindIndex): TaxSubject {
            $subject = new TaxSubject();
            $subject->type = SubjectType::PF;
            $subject->country_code = 'RO';
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
                'tax_residency_country' => 'RO',
                'county_code' => $input->countyCode,
                'locality_ciphertext' => $input->locality,
                'address_ciphertext' => $input->address,
                'anaf_tax_office_code' => $input->anafTaxOfficeCode,
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
                payloadHash: $this->audit->hashPayload(['type' => SubjectType::PF->value]),
            ));

            return $subject;
        });
    }
}
