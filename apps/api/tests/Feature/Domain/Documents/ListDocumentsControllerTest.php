<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Documents\ListDocumentsControllerTest;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Enums\ExtractionStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Wallet\Enums\AccountKind;
use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Support\KeyManagement\SubjectDekService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;

/**
 * `GET /api/v1/subjects/{subject}/documents` — the server-side inbox that replaced listing a
 * person's documents out of the browser database of whichever device uploaded them.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );
});

/**
 * The id column of a JSON page, as a list of strings.
 *
 * `TestResponse::json()` is typed `mixed`, and handing that straight to `array_intersect` is a
 * static-analysis error rather than a runtime one — narrowed here once instead of at each call.
 *
 * @return list<string>
 */
function idsFrom(mixed $ids): array
{
    return array_values(array_filter(
        is_array($ids) ? $ids : [],
        static fn (mixed $id): bool => is_string($id),
    ));
}

/** @return array{0: User, 1: TaxSubject} */
function memberAndSubject(): array
{
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    return [$user, $subject];
}

/**
 * A raw `document_extractions` row.
 *
 * Written through the query builder rather than the model: `DocumentExtraction` encrypts
 * `result_ciphertext` under the subject's DEK, and these tests only care that a row with a given
 * status exists — the endpoint counts rows, it never decrypts them.
 */
function insertExtraction(string $documentId, ExtractionStatus $status): void
{
    DB::table('document_extractions')->insert([
        'id' => (string) Str::uuid7(),
        'document_id' => $documentId,
        'extractor_type' => 'NATIVE_PDF_TEXT',
        'extractor_version' => '1.0.0',
        'schema_version' => '1',
        'result_ciphertext' => '{}',
        'confidence_decimal' => '0.9000',
        'status' => $status->value,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
}

it('lists the subject documents newest first, under the name each is presented by', function (): void {
    [$user, $subject] = memberAndSubject();

    Document::factory()->create([
        'tax_subject_id' => $subject->id,
        // Named by its owner, so that is what the list shows.
        'display_name_ciphertext' => 'chirie-ianuarie.pdf',
        'mime_type' => 'application/pdf',
        'received_at' => Carbon::parse('2026-01-10T09:00:00Z'),
    ]);
    Document::factory()->create([
        'tax_subject_id' => $subject->id,
        // Never named, and the file arrived called `IMG_0042.jpg` — which is what every photograph
        // a phone takes is called, so it identifies nothing and is not used as the label.
        'original_filename_ciphertext' => 'IMG_0042.jpg',
        'display_name_ciphertext' => null,
        'mime_type' => 'image/jpeg',
        'sha256' => str_repeat('deadbeef', 8),
        'received_at' => Carbon::parse('2026-03-02T09:00:00Z'),
    ]);

    $response = $this->actingAs($user, 'web')->getJson("/api/v1/subjects/{$subject->id}/documents")->assertOk();

    // A chosen name is decrypted for its owner — encryption at rest protects a stolen backup, and
    // was never meant to hide a person's own documents from them. An unnamed one falls back to its
    // content hash rather than to whatever the camera called it.
    $response->assertJsonPath('data.0.filename', 'deadbeefdeadbeef.jpg')
        ->assertJsonPath('data.1.filename', 'chirie-ianuarie.pdf');
});

it('never exposes the object path a document is stored under', function (): void {
    [$user, $subject] = memberAndSubject();
    Document::factory()->create(['tax_subject_id' => $subject->id]);

    $row = $this->actingAs($user, 'web')->getJson("/api/v1/subjects/{$subject->id}/documents")->assertOk()
        ->json('data.0');

    expect($row)->toBeArray()
        ->and($row)->not->toHaveKey('object_path')
        ->and($row)->not->toHaveKey('sha256')
        ->and($row)->not->toHaveKey('original_filename_ciphertext');
});

it('never lists another subject documents', function (): void {
    [$userA, $subjectA] = memberAndSubject();
    [, $subjectB] = memberAndSubject();

    Document::factory()->create(['tax_subject_id' => $subjectB->id]);

    $this->actingAs($userA, 'web')->getJson("/api/v1/subjects/{$subjectA->id}/documents")->assertOk()
        ->assertJsonCount(0, 'data');
});

it('refuses a user with no membership on the subject', function (): void {
    [, $subject] = memberAndSubject();
    $outsider = User::factory()->create();

    $this->actingAs($outsider, 'web')->getJson("/api/v1/subjects/{$subject->id}/documents")->assertStatus(403);
});

it('filters by status and by type', function (): void {
    [$user, $subject] = memberAndSubject();

    Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::READY,
        'type' => DocumentType::INVOICE,
    ]);
    Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::QUARANTINE,
        'type' => DocumentType::RECEIPT,
    ]);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents?status=READY")->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'INVOICE');

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents?type=RECEIPT")->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'QUARANTINE');
});

it('rejects a status or type that is not a real enum case', function (): void {
    [$user, $subject] = memberAndSubject();

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents?status=DEFINITELY_NOT")->assertStatus(422);
});

it('filters by received date inclusively at both ends', function (): void {
    [$user, $subject] = memberAndSubject();

    foreach (['2026-01-05', '2026-02-15', '2026-03-25'] as $date) {
        Document::factory()->create([
            'tax_subject_id' => $subject->id,
            'received_at' => Carbon::parse("{$date}T12:00:00Z"),
        ]);
    }

    // "Up to 15 February" must include everything that arrived on the 15th, not stop at midnight.
    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents?received_from=2026-01-05&received_to=2026-02-15")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('counts only the proposals still waiting on a human', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::READY,
    ]);

    foreach ([ExtractionStatus::PROPOSED, ExtractionStatus::PROPOSED, ExtractionStatus::ACCEPTED] as $status) {
        insertExtraction($document->id, $status);
    }

    $this->actingAs($user, 'web')->getJson("/api/v1/subjects/{$subject->id}/documents")->assertOk()
        ->assertJsonPath('data.0.pending_extractions', 2);
});

it('narrows to documents nobody has dealt with yet', function (): void {
    [$user, $subject] = memberAndSubject();

    // Outstanding: READY, nothing accepted, no transaction. A photograph no parser can read counts
    // — it needs a human precisely *because* nothing could read it.
    $outstanding = Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::READY,
    ]);

    // Dealt with: a person accepted a reading of it.
    $accepted = Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::READY,
    ]);
    insertExtraction($accepted->id, ExtractionStatus::ACCEPTED);

    // Not READY, so not actionable at all yet.
    Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::QUARANTINE,
    ]);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents?needs_review=1")->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $outstanding->id);
});

it('treats a document already turned into a transaction as dealt with', function (): void {
    [$user, $subject] = memberAndSubject();

    $used = Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::READY,
    ]);

    // The screen used to contradict itself here: a document showed a Review button while the filter
    // insisted nothing needed reviewing. Acting on it is what makes it done.
    $account = FinancialAccount::create([
        'tax_subject_id' => $subject->id,
        'kind' => AccountKind::BANK_CURRENT,
        'display_name_ciphertext' => 'Cont',
        'currency_code' => 'RON',
        'source' => RecordSource::MANUAL,
    ]);

    FinancialTransaction::create([
        'tax_subject_id' => $subject->id,
        'account_id' => $account->id,
        'booked_at' => '2026-03-04',
        'amount_minor' => -1340,
        'currency_code' => 'RON',
        'description_ciphertext' => 'Kaufland',
        'counterparty_ciphertext' => 'Kaufland',
        'source' => RecordSource::DOCUMENT,
        'document_id' => $used->id,
    ]);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents?needs_review=1")->assertOk()
        ->assertJsonCount(0, 'data');
});

it('pages without repeating or skipping a row when documents share a received timestamp', function (): void {
    [$user, $subject] = memberAndSubject();
    $sameInstant = Carbon::parse('2026-04-01T08:00:00Z');

    for ($i = 0; $i < 5; $i++) {
        Document::factory()->create(['tax_subject_id' => $subject->id, 'received_at' => $sameInstant]);
    }

    $first = $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents?per_page=2")->assertOk();

    $cursor = $first->json('next_cursor');
    expect($cursor)->toBeString();

    // URL-encoded, as any client must: the cursor is base64 and an unescaped `+` in a query string
    // decodes to a space, which Laravel then discards as a malformed cursor — silently serving page
    // one again rather than erroring.
    $encoded = urlencode(is_string($cursor) ? $cursor : '');

    $second = $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents?per_page=2&cursor={$encoded}")->assertOk();

    // Ordering on the UUIDv7 primary key is what stops a shared timestamp from repeating a row
    // across the page boundary — see the controller for the sqlite comparison that made ordering on
    // `received_at` return page one forever.
    $firstIds = idsFrom($first->json('data.*.id'));
    $secondIds = idsFrom($second->json('data.*.id'));

    expect(array_values(array_intersect($firstIds, $secondIds)))->toBe([]);
    expect($firstIds)->toHaveCount(2);
    expect($secondIds)->toHaveCount(2);
});
