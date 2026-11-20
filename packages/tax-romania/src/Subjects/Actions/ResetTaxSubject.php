<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Actions;

use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Documents\Actions\DeleteDocument;
use Kern\Documents\Models\Document;
use Kern\Platform\Identity\Avatars\RemoveAvatar;
use Kern\TaxRomania\Spv\Actions\DeleteSpvConnection;
use Kern\TaxRomania\StatementImport\Actions\DeleteStatementImport;
use Kern\TaxRomania\StatementImport\Enums\StatementImportStatus;
use Kern\TaxRomania\StatementImport\Exceptions\ImportNotOpenException;
use Kern\TaxRomania\StatementImport\Models\StatementImport;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * Empties a taxpayer back to nothing — every account, deposit, holding, document, import, rental,
 * property and computed tax figure the subject owns — and leaves it an anonymous, reusable stub.
 *
 * ## Why the row itself survives
 *
 * A `tax_subjects` row that has ever acted cannot be deleted: `audit_events`/`audit_chain_heads`
 * reference it `ON DELETE RESTRICT`, and `audit_events` carries a `BEFORE DELETE/UPDATE` trigger
 * (`audit_events is append-only`), so the subject's immutable action log can never go. A reset
 * therefore strips everything *under* the subject and then scrubs the subject's identity, rather
 * than removing the row. What remains is a nameless husk the audit chain hangs off.
 *
 * ## The CNP is kept as a one-way hash, so the taxpayer can be added again
 *
 * `fiscal_identifier_blind_index` (the hashed CNP/CUI — irreversible) is kept; the recoverable
 * `fiscal_identifier_ciphertext` and the display name are nulled. Re-adding the same CNP matches
 * that hash and {@see RegisterPfSubject} reconnects this husk instead of refusing it — so the
 * taxpayer can be recreated by anyone, without the hash ever colliding or a plaintext identifier
 * lingering. The subject's DEK is kept so that re-attach can re-store the identity against it.
 *
 * ## External artifacts go through their own delete actions
 *
 * Documents hold encrypted blobs, imports hold uploaded files, SPV connections hold external
 * session state, the taxpayer's photo is a stored blob — a bare row delete would orphan all of it.
 * So those reuse {@see DeleteDocument}, {@see DeleteStatementImport}, {@see DeleteSpvConnection}
 * and {@see RemoveAvatar}, which tear down the external state too. They run before the row-wipe
 * transaction because object-store I/O is not transactional.
 *
 * ## The row list is hand-maintained
 *
 * The pure-row deletes below name every table that hangs off `tax_subjects` except the two audit
 * tables. A table added later that references `tax_subjects` must be added here (or routed through
 * its own delete action) or the reset will silently leave its rows behind. There is no automatic
 * discovery: the test lane is SQLite, which has no portable foreign-key introspection.
 */
final readonly class ResetTaxSubject
{
    public function __construct(
        private DeleteDocument $deleteDocument,
        private DeleteSpvConnection $deleteSpvConnection,
        private DeleteStatementImport $deleteStatementImport,
        private RemoveAvatar $removeAvatar,
        private AuditChainService $audit,
    ) {
    }

    public function __invoke(TaxSubject $subject, ?string $actorId = null): void
    {
        $subjectId = $subject->id;

        // 0. Refuse before touching anything if an import is still being written. The blob, file and
        //    session deletes below are irreversible and run *before* the row-wipe transaction (object
        //    store is not transactional), and `DeleteStatementImport` refuses an `IMPORTING` import —
        //    so a reset that began would destroy the documents and SPV sessions and then abort on that
        //    refusal, leaving a half-erased taxpayer with its blobs gone for good. Caught here, the
        //    owner simply retries once the import has settled, and nothing has been touched.
        $writingImport = StatementImport::query()
            ->where('tax_subject_id', $subjectId)
            ->where('status', StatementImportStatus::IMPORTING->value)
            ->first();

        if ($writingImport !== null) {
            throw ImportNotOpenException::forImport($writingImport->id);
        }

        // 1. Anything with external state, through its own delete action (blobs, files, sessions).
        //    `withTrashed()` so a soft-deleted document's row and encrypted blob go too — the FK
        //    cascade never fires (the subject row is kept) and the DEK is kept, so a trashed blob left
        //    behind would stay decryptable by whoever re-claims the CNP.
        foreach (Document::withTrashed()->where('tax_subject_id', $subjectId)->get() as $document) {
            ($this->deleteDocument)($document, $actorId);
        }

        // Enumerated through the query builder, not the Spv model: this module drives Spv's own delete
        // action but does not import its Eloquent model to do it.
        foreach (DB::table('spv_connections')->where('tax_subject_id', $subjectId)->pluck('id') as $connectionId) {
            if (is_string($connectionId)) {
                ($this->deleteSpvConnection)($connectionId, $actorId);
            }
        }

        foreach (StatementImport::query()->where('tax_subject_id', $subjectId)->get() as $import) {
            ($this->deleteStatementImport)($import, $actorId);
        }

        // The taxpayer's photo is an external blob too. The scrub below would otherwise leave
        // `avatar_path` pointing at a face that whoever re-adds this CNP could open — so it is torn
        // down here (column nulled, blob deleted) by the same action the avatar screen uses.
        ($this->removeAvatar)($subject, $actorId);

        // 2. Everything else the subject owns — pure rows, children before parents. The whole wallet
        //    subtree (transactions, splits, deposits, rate periods, interest events, maturity
        //    reminders, investment lots, payouts, reminders) cascades from `financial_accounts`.
        DB::transaction(function () use ($subjectId): void {
            DB::table('financial_accounts')->where('tax_subject_id', $subjectId)->delete();

            DB::table('transaction_tags')->where('tax_subject_id', $subjectId)->delete();
            DB::table('tags')->where('tax_subject_id', $subjectId)->delete();
            DB::table('counterparty_tags')->where('tax_subject_id', $subjectId)->delete();
            DB::table('counterparty_rules')->where('tax_subject_id', $subjectId)->delete();

            // SPV inbox messages are scoped to the subject directly, not to the connection, so
            // DeleteSpvConnection never touches them and the CASCADE on their tax_subjects FK never
            // fires (the row is kept). Left behind, they would stay decryptable under the kept DEK, so
            // they are cleared explicitly. Their attached documents already went via the Document loop.
            DB::table('spv_messages')->where('tax_subject_id', $subjectId)->delete();

            // tax_assessment_lines carries no tax_subject_id of its own — it hangs off
            // tax_assessments by assessment_id (NO ACTION) — so this subject's lines are cleared
            // through their parent assessments, before those assessments go.
            DB::table('tax_assessment_lines')
                ->whereIn('assessment_id', function ($query) use ($subjectId): void {
                    $query->select('id')->from('tax_assessments')->where('tax_subject_id', $subjectId);
                })
                ->delete();

            // declaration_submissions (filed receipts — encrypted, no tax_subject_id of their own)
            // reference declarations by declaration_id under a NO ACTION FK, so they go before the
            // declarations they belong to, or Postgres refuses to drop a declaration that was filed.
            DB::table('declaration_submissions')
                ->whereIn('declaration_id', function ($query) use ($subjectId): void {
                    $query->select('id')->from('declarations')->where('tax_subject_id', $subjectId);
                })
                ->delete();

            // declarations reference tax_assessments (NO ACTION), so they are deleted before the
            // assessments they point at, or Postgres refuses to drop the assessments.
            DB::table('declarations')->where('tax_subject_id', $subjectId)->delete();
            DB::table('tax_assessments')->where('tax_subject_id', $subjectId)->delete();
            DB::table('tax_facts')->where('tax_subject_id', $subjectId)->delete();

            DB::table('rental_accruals')->where('tax_subject_id', $subjectId)->delete();
            DB::table('rental_contract_versions')->where('tax_subject_id', $subjectId)->delete();
            DB::table('rental_contracts')->where('tax_subject_id', $subjectId)->delete();
            DB::table('properties')->where('tax_subject_id', $subjectId)->delete();

            DB::table('document_ai_consents')->where('tax_subject_id', $subjectId)->delete();

            DB::table('pf_profiles')->where('tax_subject_id', $subjectId)->delete();
            DB::table('pfa_profiles')->where('tax_subject_id', $subjectId)->delete();

            // 3. The taxpayer leaves everyone's list: revoke every membership still in force.
            SubjectMembership::query()
                ->where('subject_id', $subjectId)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now('UTC')]);

            // 4. Scrub the shell: drop the name and the recoverable identifier, keep only the one-way
            //    hash (so the CNP can be re-added and reconnected) and the DEK (so it can be
            //    re-stored). Mark it closed until a re-attach brings it back to active.
            DB::table('tax_subjects')->where('id', $subjectId)->update([
                'display_name_ciphertext' => null,
                'fiscal_identifier_ciphertext' => null,
                'fiscal_identifier_kind' => null,
                'status' => SubjectStatus::CLOSED->value,
                'updated_at' => now('UTC'),
            ]);
        });

        // Read-after: the subject is now a husk, but its chain lives on and this is its newest link.
        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $subjectId,
            actorId: $actorId,
            eventType: 'TaxSubjectReset',
            resourceType: 'TaxSubject',
            resourceId: $subjectId,
            payloadHash: $this->audit->hashPayload(['reset' => true]),
        ));
    }
}
