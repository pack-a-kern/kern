<?php

declare(strict_types=1);

namespace Tests\Unit\Support\ObjectStore\ObjectKeyTest;

use Illuminate\Support\Str;
use Kern\Platform\ObjectStore\DocumentObjectKind;
use Kern\Platform\ObjectStore\Exceptions\InvalidObjectKeyException;
use Kern\Platform\ObjectStore\ObjectKey;

it('builds the exact BLUEPRINT.md §4.6 key shape for a document original', function (): void {
    $subjectId = (string) Str::uuid7();
    $documentId = (string) Str::uuid7();

    $key = ObjectKey::forDocument($subjectId, $documentId, DocumentObjectKind::ORIGINAL);

    expect($key->value)->toBe("subjects/{$subjectId}/documents/{$documentId}/original.enc");
});

it('names the OCR derivative ocr.json.enc, matching the blueprint example exactly', function (): void {
    $key = ObjectKey::forDocument((string) Str::uuid7(), (string) Str::uuid7(), DocumentObjectKind::OCR);

    expect($key->value)->toEndWith('/ocr.json.enc');
});

it('derives the manifest key by appending .manifest', function (): void {
    $key = ObjectKey::forDocument((string) Str::uuid7(), (string) Str::uuid7(), DocumentObjectKind::PREVIEW);

    expect($key->manifestKey())->toBe($key->value . '.manifest');
});

it('accepts a ULID subject or document id, not only UUID', function (): void {
    $key = ObjectKey::forDocument((string) Str::ulid(), (string) Str::ulid(), DocumentObjectKind::THUMBNAIL);

    expect($key->value)->toContain('subjects/')->toContain('/documents/');
});

it('rejects a CNP-shaped string as a subject id — object keys cannot carry PII by construction', function (): void {
    ObjectKey::forDocument('1234567890123', (string) Str::uuid7(), DocumentObjectKind::ORIGINAL);
})->throws(InvalidObjectKeyException::class);

it('rejects an email-shaped string as a document id', function (): void {
    ObjectKey::forDocument((string) Str::uuid7(), 'someone@example.com', DocumentObjectKind::ORIGINAL);
})->throws(InvalidObjectKeyException::class);

it('rejects a free-text filename standing in for a subject id', function (): void {
    ObjectKey::forDocument('Ion Popescu.pdf', (string) Str::uuid7(), DocumentObjectKind::ORIGINAL);
})->throws(InvalidObjectKeyException::class);

it('rejects an empty string', function (): void {
    ObjectKey::forDocument('', (string) Str::uuid7(), DocumentObjectKind::ORIGINAL);
})->throws(InvalidObjectKeyException::class);
