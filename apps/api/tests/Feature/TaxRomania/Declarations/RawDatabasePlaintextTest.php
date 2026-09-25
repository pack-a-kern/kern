<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Declarations\RawDatabasePlaintextTest;

use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Declarations\Enums\SubmissionTransport;
use Kern\TaxRomania\Declarations\Models\Declaration;
use Kern\TaxRomania\Declarations\Models\DeclarationSubmission;
use Kern\TaxRomania\Declarations\Models\FormVersion;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;

/**
 * `BLUEPRINT.md` §4.3 requires `declaration_submissions.external_reference_ciphertext`/
 * `response_ciphertext` to be encrypted — a raw dump of the row must not contain the plaintext
 * that went in. Mirrors `tests/Feature/TaxRomania/Rental/RawDatabasePlaintextTest.php`'s exact proof
 * style.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

it('never stores a submission\'s plaintext external reference or response payload anywhere in the raw row', function (): void {
    $subject = TaxSubject::factory()->create();
    $user = User::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);

    $assessment = TaxAssessment::factory()->final()->create([
        'tax_subject_id' => $subject->id,
        'fiscal_year' => 2026,
    ]);
    $formVersion = FormVersion::factory()->create();

    $declaration = Declaration::create([
        'tax_subject_id' => $subject->id,
        'form_version_id' => $formVersion->id,
        'fiscal_year' => 2026,
        'assessment_id' => $assessment->id,
    ]);

    $plaintextReference = 'Recognisable ANAF reference ANAF-2026-998877';

    $submission = DeclarationSubmission::record(
        $declaration,
        SubmissionTransport::MANUAL,
        new DateTimeImmutable('2026-06-01'),
        $plaintextReference,
    );

    $plaintextResponse = 'Recognisable ANAF response body: filing accepted, receipt 12345.';
    $submission->recordResponse($plaintextResponse, null);

    /** @var object{external_reference_ciphertext: string, response_ciphertext: string}|null $raw */
    $raw = DB::table('declaration_submissions')->where('id', $submission->id)->first();
    expect($raw)->not->toBeNull();
    assert($raw !== null);

    foreach ([$raw->external_reference_ciphertext, $raw->response_ciphertext] as $column) {
        expect($column)->not->toContain($plaintextReference)
            ->and($column)->not->toContain($plaintextResponse)
            ->and((string) base64_decode($column, true))->not->toContain($plaintextReference)
            ->and((string) base64_decode($column, true))->not->toContain($plaintextResponse);
    }
});
