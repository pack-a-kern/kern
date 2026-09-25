<?php

declare(strict_types=1);

namespace Tests\Feature\Documents\ListDocumentsControllerTest;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kern\Documents\Enums\DocumentStatus;
use Kern\Documents\Enums\DocumentType;
use Kern\Documents\Enums\ExtractionStatus;
use Kern\Documents\Enums\ExtractorType;
use Kern\Documents\Models\Document;
use Kern\Documents\Models\DocumentExtraction;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;

/**
 * `GET /api/v1/subjects/{subject}/documents` — the server-side inbox that replaced listing a
 * person's documents out of the browser database of whichever device uploaded them.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
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
 * A `document_extractions` row with a given status.
 *
 * Through the model, encrypted like every real reading: the list decrypts the newest reading of each
 * document for the name it proposed, so a row written in clear would be one production never holds.
 */
function insertExtraction(Document $document, ExtractionStatus $status): void
{
    $extraction = DocumentExtraction::propose($document, ExtractorType::NATIVE_PDF_TEXT, '1.0.0', null, null, '1', [], '0.9000');

    DB::table('document_extractions')->where('id', $extraction->id)->update(['status' => $status->value]);
}

it('lists the subject documents newest first, under the name each is presented by', function (): void {
    [$user, $subject] = memberAndSubject();

    Document::factory()->part(['mime_type' => 'application/pdf'])->create(['tax_subject_id' => $subject->id, // Named by its owner, so that is what the list shows.
        'display_name_ciphertext' => 'chirie-ianuarie.pdf', 'received_at' => Carbon::parse('2026-01-10T09:00:00Z')]);
    // Never named, and the file arrived called `IMG_0042.jpg` — which is what every photograph a
    // phone takes is called, so it identifies nothing and is not used as the label.
    Document::factory()
        ->part(['mime_type' => 'image/jpeg', 'original_filename_ciphertext' => 'IMG_0042.jpg'])
        ->create([
            'tax_subject_id' => $subject->id,
            'display_name_ciphertext' => null,
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

it('separates a reading waiting to be accepted from one waiting to be imported', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::READY,
    ]);

    insertExtraction($document, ExtractionStatus::PROPOSED);
    insertExtraction($document, ExtractionStatus::ACCEPTED);

    // Accepting used to end it. It no longer does: accepting is the middle of the flow — a person
    // agrees to what was read, and *then* imports it — and the two halves are different stages so
    // that a list can say which of them it is asking for.
    $this->actingAs($user, 'web')->getJson("/api/v1/subjects/{$subject->id}/documents")->assertOk()
        ->assertJsonPath('data.0.stage', 'TO_IMPORT');
});

it('narrows to the documents waiting to be imported, and to those waiting to be read', function (): void {
    [$user, $subject] = memberAndSubject();

    $unread = Document::factory()->create(['tax_subject_id' => $subject->id, 'status' => DocumentStatus::READY]);
    insertExtraction($unread, ExtractionStatus::PROPOSED);

    $accepted = Document::factory()->create(['tax_subject_id' => $subject->id, 'status' => DocumentStatus::READY]);
    insertExtraction($accepted, ExtractionStatus::ACCEPTED);

    // Nothing could read it at all — the reading failed, so there is no proposal. It belongs with
    // the documents waiting on a person, not with the ones waiting to be imported: there is nothing
    // to import from a document nothing was read from.
    $unreadable = Document::factory()->create(['tax_subject_id' => $subject->id, 'status' => DocumentStatus::READY]);

    // The filter and the badge are computed by different code — SQL narrowing a list, PHP labelling
    // a row — so each assertion checks that the rows returned carry the stage that was asked for.
    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents?stage=ACTION_NEEDED")->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $unreadable->id)
        ->assertJsonPath('data.0.stage', 'ACTION_NEEDED')
        ->assertJsonPath('data.1.id', $unread->id);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents?stage=TO_IMPORT")->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $accepted->id)
        ->assertJsonPath('data.0.stage', 'TO_IMPORT');
});

it('reports a read document nobody has decided on as needing attention', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::READY,
    ]);

    insertExtraction($document, ExtractionStatus::PROPOSED);

    $this->actingAs($user, 'web')->getJson("/api/v1/subjects/{$subject->id}/documents")->assertOk()
        ->assertJsonPath('data.0.stage', 'ACTION_NEEDED');
});

it('narrows to documents nobody has dealt with yet', function (): void {
    [$user, $subject] = memberAndSubject();

    // Outstanding: READY, nothing accepted, no transaction. A photograph no parser can read counts
    // — it needs a human precisely *because* nothing could read it.
    $outstanding = Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::READY,
    ]);

    // Also outstanding: accepted, but nothing has been imported from it yet.
    $accepted = Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::READY,
    ]);
    insertExtraction($accepted, ExtractionStatus::ACCEPTED);

    // Not READY, so not actionable at all yet.
    Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::QUARANTINE,
    ]);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents?needs_review=1")->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.id', $outstanding->id)
        ->assertJsonPath('data.0.id', $accepted->id);
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
        'kind' => AccountKind::GENERAL,
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

    $seen = [];

    for ($page = 1; $page <= 3; $page++) {
        $response = $this->actingAs($user, 'web')
            ->getJson("/api/v1/subjects/{$subject->id}/documents?per_page=2&page={$page}")->assertOk();

        $seen = [...$seen, ...idsFrom($response->json('data.*.id'))];
    }

    // Ordering on the UUIDv7 primary key is what stops a shared timestamp from repeating a row
    // across a page boundary: five documents created in the same millisecond still have a strict,
    // unique order, so paging them yields each exactly once.
    expect($seen)->toHaveCount(5)
        ->and(array_unique($seen))->toHaveCount(5);
});

it('reports the total across every page, not the size of the page returned', function (): void {
    [$user, $subject] = memberAndSubject();

    for ($i = 0; $i < 5; $i++) {
        Document::factory()->create(['tax_subject_id' => $subject->id]);
    }

    // The total is what lets a pager say "1–2 of 5" and offer three pages; a cursor could answer
    // neither, which is why this endpoint pages by number.
    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents?per_page=2")->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('total', 5)
        ->assertJsonPath('current_page', 1)
        ->assertJsonPath('last_page', 3);
});

it('counts only what the filters match, so the pager does not offer empty pages', function (): void {
    [$user, $subject] = memberAndSubject();

    Document::factory()->create(['tax_subject_id' => $subject->id, 'type' => DocumentType::INVOICE]);
    for ($i = 0; $i < 4; $i++) {
        Document::factory()->create(['tax_subject_id' => $subject->id, 'type' => DocumentType::RECEIPT]);
    }

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents?type=INVOICE")->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('last_page', 1);
});

it('carries the name the newest reading proposed, and none for a document nothing has read', function (): void {
    [$user, $subject] = memberAndSubject();

    $read = Document::factory()->part(['mime_type' => 'application/pdf'])->create([
        'tax_subject_id' => $subject->id,
        'received_at' => Carbon::parse('2026-01-10T09:00:00Z'),
    ]);

    foreach (['factura-veche', 'factura-enel-martie-2026'] as $name) {
        DocumentExtraction::propose($read, ExtractorType::EXTERNAL_DOCUMENT_AI, '1.0.0', 'test', 'test-model', '1', ['suggested_filename' => $name], null);
        // Ids are UUIDv7, ordered by the millisecond they were made in: two readings made in the same
        // one would have no newest between them.
        usleep(2_000);
    }

    Document::factory()->part(['mime_type' => 'application/pdf'])->create([
        'tax_subject_id' => $subject->id,
        'received_at' => Carbon::parse('2026-02-10T09:00:00Z'),
    ]);

    $this->actingAs($user, 'web')->getJson("/api/v1/subjects/{$subject->id}/documents")->assertOk()
        ->assertJsonPath('data.0.proposed_name', null)
        ->assertJsonPath('data.1.proposed_name', 'factura-enel-martie-2026');
});
