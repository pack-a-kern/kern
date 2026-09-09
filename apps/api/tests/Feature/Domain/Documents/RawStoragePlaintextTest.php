<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Documents\RawStoragePlaintextTest;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Quarantine\QuarantineObjectStore;
use App\Support\KeyManagement\SubjectDekService;
use App\Support\ObjectStore\DocumentObjectKind;
use App\Support\ObjectStore\EncryptedObjectStore;
use App\Support\ObjectStore\ObjectKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;

/**
 * `BLUEPRINT.md` §4.1/§4.3/§4.6 — the raw-database and raw-bucket assertions `docs/handoff/
 * 022-document-pipeline.md`'s brief calls out explicitly: a `documents` row's own filename and a
 * document's stored bytes must both be unreadable without going through the application's crypto
 * layer, whether an attacker reads Postgres directly or dumps the object-store bucket. This is
 * this module's own version of `docs/handoff/012-encrypted-object-store.md`'s "never stores the
 * plaintext marker" proof and `tests/Feature/Domain/Subjects/RawDatabasePlaintextTest.php`'s raw
 * -column proof, applied to the tables/objects this module owns.
 */
beforeEach(function (): void {
    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );

    Storage::fake('quarantine');
    Storage::fake('s3');
});

/** @return resource */
function streamOf(string $content)
{
    $stream = fopen('php://temp', 'w+b');

    if ($stream === false) {
        throw new RuntimeException('Unable to open a memory stream.');
    }

    fwrite($stream, $content);
    rewind($stream);

    return $stream;
}

it('never stores a document\'s plaintext original filename anywhere in the raw documents row', function (): void {
    $recognisableFilename = 'Extras-Cont-Recognisable-BT-2026.pdf';

    $document = Document::factory()->create(['original_filename_ciphertext' => $recognisableFilename]);

    /** @var object{original_filename_ciphertext: string}|null $raw */
    $raw = DB::table('documents')->where('id', $document->id)->first();
    expect($raw)->not->toBeNull();
    assert($raw !== null);

    expect($raw->original_filename_ciphertext)->not->toContain($recognisableFilename)
        ->and((string) base64_decode($raw->original_filename_ciphertext, true))->not->toContain($recognisableFilename);
});

it('never stores a document\'s plaintext content in the raw quarantine bucket', function (): void {
    $document = Document::factory()->create();
    $marker = 'CNP-1900101234567-RECOGNISABLE-MARKER';
    $key = ObjectKey::forDocument($document->tax_subject_id, $document->id, DocumentObjectKind::ORIGINAL);

    app(QuarantineObjectStore::class)->put($key, streamOf(str_repeat('filler ', 50) . $marker . str_repeat(' filler', 50)));

    $raw = Storage::disk('quarantine')->get($key->value);

    expect($raw)->not->toBeNull()->not->toContain($marker);
});

it('never stores a document\'s plaintext content in the raw real object-store bucket', function (): void {
    $document = Document::factory()->create();
    $marker = 'IBAN-RO00BTRL0000000000000000-RECOGNISABLE-MARKER';
    $key = ObjectKey::forDocument($document->tax_subject_id, $document->id, DocumentObjectKind::ORIGINAL);

    app(EncryptedObjectStore::class)->put($key, streamOf(str_repeat('filler ', 50) . $marker . str_repeat(' filler', 50)));

    $raw = Storage::disk('s3')->get($key->value);

    expect($raw)->not->toBeNull()->not->toContain($marker);
});

it('never stores extracted document text in plaintext in the real object-store bucket', function (): void {
    $document = Document::factory()->create();
    $extractedText = 'CNP 1900101234567 IBAN RO00BTRL0000000000000000 RECOGNISABLE-EXTRACTED-TEXT-MARKER';
    $key = ObjectKey::forDocument($document->tax_subject_id, $document->id, DocumentObjectKind::EXTRACTED_TEXT);

    app(EncryptedObjectStore::class)->put($key, streamOf($extractedText));

    $raw = Storage::disk('s3')->get($key->value);

    expect($raw)->not->toBeNull()->not->toContain($extractedText)
        ->and($raw)->not->toContain('RECOGNISABLE-EXTRACTED-TEXT-MARKER');
});
