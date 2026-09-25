<?php

declare(strict_types=1);

namespace Kern\Documents\Tests\Unit\BroadcastPayloadContainsNoPiiTest;

use Kern\Documents\Enums\DocumentStatus;
use Kern\Documents\Enums\ExtractionStatus;
use Kern\Documents\Enums\RejectionReason;
use Kern\Documents\Events\DocumentExtractionProposed;
use Kern\Documents\Events\DocumentScanned;
use Kern\Documents\Events\DocumentTextExtracted;
use Kern\Documents\Events\DocumentTextExtractionRejected;
use Kern\Documents\Events\DocumentUploaded;
use ReflectionClass;
use ReflectionProperty;

/**
 * `BLUEPRINT.md` §16 — broadcast events carry status and progress only, never document text or any
 * other PII. Constructs every `Kern\Documents\Events\*` broadcast event directly (no
 * database, no queue, no Reverb connection needed — `broadcastWith()` is a pure method) and
 * asserts its payload is exactly a closed, small set of safe keys.
 *
 * This does not merely check for one hard-coded marker string — it asserts the **entire key set**
 * each event may ever broadcast, so a future edit that adds a new field must deliberately update
 * this test's expectation, not silently smuggle a new PII-bearing key past it.
 */
it('DocumentUploaded broadcasts only document_id, status, and progress', function (): void {
    $event = new DocumentUploaded('doc-1', 'subject-1', DocumentStatus::QUARANTINE);

    expect($event->broadcastWith())->toBe([
        'document_id' => 'doc-1',
        'status' => 'QUARANTINE',
        'progress' => 10,
    ]);
});

it('DocumentScanned broadcasts only document_id, status, rejection_reason, and progress', function (): void {
    $event = new DocumentScanned('doc-1', 'subject-1', DocumentStatus::REJECTED, RejectionReason::INFECTED);

    expect($event->broadcastWith())->toBe([
        'document_id' => 'doc-1',
        'status' => 'REJECTED',
        'rejection_reason' => 'INFECTED',
        'progress' => 100,
    ]);
});

it('DocumentTextExtracted broadcasts only document_id, status, needs_ocr, and progress — never the extracted text', function (): void {
    $event = new DocumentTextExtracted('doc-1', 'subject-1', DocumentStatus::READY, true);

    expect($event->broadcastWith())->toBe([
        'document_id' => 'doc-1',
        'status' => 'READY',
        'needs_ocr' => true,
        'progress' => 70,
    ]);
});

it('DocumentExtractionProposed broadcasts only ids and statuses — never the extraction result', function (): void {
    $event = new DocumentExtractionProposed('doc-1', 'subject-1', 'extraction-1', DocumentStatus::READY, ExtractionStatus::PROPOSED);

    expect($event->broadcastWith())->toBe([
        'document_id' => 'doc-1',
        'extraction_id' => 'extraction-1',
        'status' => 'READY',
        'extraction_status' => 'PROPOSED',
        'progress' => 90,
    ]);
});

it('DocumentTextExtractionRejected broadcasts only document_id, status, rejection_reason, and progress', function (): void {
    $event = new DocumentTextExtractionRejected('doc-1', 'subject-1', RejectionReason::ENCRYPTED_PDF);

    expect($event->broadcastWith())->toBe([
        'document_id' => 'doc-1',
        'status' => 'REJECTED',
        'rejection_reason' => 'ENCRYPTED_PDF',
        'progress' => 100,
    ]);
});

it('no Documents broadcast event class defines a public property carrying extracted text or a filename', function (): void {
    $classes = [DocumentUploaded::class, DocumentScanned::class, DocumentTextExtracted::class, DocumentTextExtractionRejected::class, DocumentExtractionProposed::class];

    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            expect(mb_strtolower($property->getName()))
                ->not->toContain('text')
                ->not->toContain('filename')
                ->not->toContain('content')
                ->not->toContain('result');
        }
    }
});
