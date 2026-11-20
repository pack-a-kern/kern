<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Subjects\ResetTaxSubjectTest;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Tenancy\TenantContext;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Declarations\Models\FormVersion;
use Kern\TaxRomania\StatementImport\Enums\StatementImportStatus;
use Kern\TaxRomania\StatementImport\Exceptions\ImportNotOpenException;
use Kern\TaxRomania\Subjects\Actions\ResetTaxSubject;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessmentLine;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;

beforeEach(function (): void {
    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );
});

it('wipes a taxpayer to an anonymous stub, keeping the CNP hash and revoking access', function (): void {
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    // An identity to scrub, with a CNP hash that must survive.
    DB::table('tax_subjects')->where('id', $subject->id)->update([
        'fiscal_identifier_blind_index' => str_repeat('a', 64),
        'fiscal_identifier_kind' => 'CNP',
        'status' => SubjectStatus::ACTIVE->value,
    ]);

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    app(TenantContext::class)->authorizeAndActivate($user->id, $subject->id);

    $account = FinancialAccount::create([
        'tax_subject_id' => $subject->id,
        'kind' => AccountKind::GENERAL,
        'display_name_ciphertext' => null,
        'currency_code' => 'RON',
        'source' => RecordSource::MANUAL,
    ]);

    DB::table('financial_transactions')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'tax_subject_id' => $subject->id,
        'account_id' => $account->id,
        'amount_minor' => 5_000_000,
        'currency_code' => 'RON',
        'booked_at' => '2026-01-10',
        'description_ciphertext' => 'x',
        'counterparty_ciphertext' => 'x',
        'created_at' => now('UTC'),
    ]);

    // A tax assessment, one of its lines, and a declaration pointing at it. The line hangs off the
    // assessment (assessment_id), not the subject, and the declaration references the assessment
    // under a NO ACTION FK — so a wipe that keyed lines on a column they lack, or dropped assessments
    // before the declarations that point at them, fails here (Postgres errors; SQLite enforces the FK
    // and the string-literal quirk leaves an un-deleted line). Seeded so the wipe must clear all three.
    $assessment = TaxAssessment::factory()->create(['tax_subject_id' => $subject->id]);
    TaxAssessmentLine::factory()->create(['assessment_id' => $assessment->id]);

    $declarationId = (string) Str::uuid();
    DB::table('declarations')->insert([
        'id' => $declarationId,
        'tax_subject_id' => $subject->id,
        'form_version_id' => FormVersion::factory()->create()->id,
        'fiscal_year' => 2026,
        'assessment_id' => $assessment->id,
        'created_at' => now('UTC'),
    ]);

    // A filed receipt: hangs off the declaration (declaration_id, NO ACTION), no tax_subject_id of
    // its own — it must go before the declaration or the wipe aborts after the blobs are gone.
    DB::table('declaration_submissions')->insert([
        'id' => (string) Str::uuid(),
        'declaration_id' => $declarationId,
        'transport' => 'SPV',
        'submitted_at' => now('UTC'),
        'created_at' => now('UTC'),
    ]);

    // An SPV inbox message: scoped to the subject directly (not the connection), so nothing else
    // clears it — left behind it would stay decryptable under the kept DEK.
    DB::table('spv_messages')->insert([
        'id' => (string) Str::uuid(),
        'tax_subject_id' => $subject->id,
        'external_id_ciphertext' => 'x',
        'external_id_blind_index' => str_repeat('b', 64),
        'title_ciphertext' => 'x',
        'received_at' => now('UTC'),
    ]);

    // A taxpayer photo: the scrub must null it and delete the blob, or the next owner of this CNP
    // could open the previous person's face through the avatar endpoint.
    $subject->saveAvatar('avatars/' . $subject->id, now('UTC'));

    app(ResetTaxSubject::class)($subject, $user->id);

    // Data gone — the wallet, the assessment chain (lines, assessments, declarations, submissions),
    // the SPV inbox, and the avatar.
    expect(FinancialAccount::query()->where('tax_subject_id', $subject->id)->count())->toBe(0);
    expect(DB::table('financial_transactions')->where('tax_subject_id', $subject->id)->count())->toBe(0);
    expect(DB::table('tax_assessment_lines')->where('assessment_id', $assessment->id)->count())->toBe(0);
    expect(DB::table('tax_assessments')->where('tax_subject_id', $subject->id)->count())->toBe(0);
    expect(DB::table('declaration_submissions')->where('declaration_id', $declarationId)->count())->toBe(0);
    expect(DB::table('declarations')->where('tax_subject_id', $subject->id)->count())->toBe(0);
    expect(DB::table('spv_messages')->where('tax_subject_id', $subject->id)->count())->toBe(0);
    expect(DB::table('tax_subjects')->where('id', $subject->id)->value('avatar_path'))->toBeNull();

    // Access revoked.
    expect(SubjectMembership::query()->where('subject_id', $subject->id)->whereNull('revoked_at')->count())->toBe(0);

    // The shell survives, scrubbed, but the CNP hash is kept so it can be re-added.
    $shell = DB::table('tax_subjects')->where('id', $subject->id);
    expect($shell->exists())->toBeTrue();
    expect($shell->value('fiscal_identifier_ciphertext'))->toBeNull();
    expect($shell->value('fiscal_identifier_kind'))->toBeNull();
    expect($shell->value('display_name_ciphertext'))->toBeNull();
    expect($shell->value('fiscal_identifier_blind_index'))->toBe(str_repeat('a', 64));
    expect($shell->value('status'))->toBe(SubjectStatus::CLOSED->value);
});

it('refuses to reset while an import is still being written, and touches nothing', function (): void {
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    app(TenantContext::class)->authorizeAndActivate($user->id, $subject->id);

    // Something the reset would wipe — proof nothing is deleted when it refuses.
    $account = FinancialAccount::create([
        'tax_subject_id' => $subject->id,
        'kind' => AccountKind::GENERAL,
        'display_name_ciphertext' => null,
        'currency_code' => 'RON',
        'source' => RecordSource::MANUAL,
    ]);

    // An import a worker is writing right now — DeleteStatementImport refuses it, and the blob
    // deletes run before the row-wipe transaction, so a reset that began would half-erase the
    // taxpayer. It must refuse up front, before touching anything.
    DB::table('statement_imports')->insert([
        'id' => (string) Str::uuid(),
        'tax_subject_id' => $subject->id,
        'account_id' => $account->id,
        'status' => StatementImportStatus::IMPORTING->value,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);

    expect(function () use ($subject, $user): void {
        app(ResetTaxSubject::class)($subject, $user->id);
    })->toThrow(ImportNotOpenException::class);

    // Untouched: the account is still there and the identity is intact.
    expect(FinancialAccount::query()->where('tax_subject_id', $subject->id)->whereKey($account->id)->exists())
        ->toBeTrue();
    expect(DB::table('tax_subjects')->where('id', $subject->id)->value('status'))->not->toBe(SubjectStatus::CLOSED->value);
});
