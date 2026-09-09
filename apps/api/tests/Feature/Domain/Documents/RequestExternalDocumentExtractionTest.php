<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Documents\RequestExternalDocumentExtractionTest;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Documents\Consent\DocumentAiConsentGate;
use App\Domain\Documents\Consent\Exceptions\AiConsentRequiredException;
use App\Domain\Documents\ExternalAi\DataTransferObjects\DocumentAiRequest;
use App\Domain\Documents\ExternalAi\DataTransferObjects\DocumentAiResponse;
use App\Domain\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderNotConfiguredException;
use App\Domain\Documents\ExternalAi\ExternalDocumentAiProvider;
use App\Domain\Documents\ExternalAi\NullDocumentAiProvider;
use App\Domain\Documents\ExternalAi\RequestExternalDocumentExtraction;
use App\Domain\Documents\Extraction\DataTransferObjects\UntrustedDocumentText;
use App\Domain\Documents\Models\Document;
use App\Support\KeyManagement\SubjectDekService;
use RuntimeException;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;

/**
 * ADR 0005's ordering, exercised directly at the action level (`docs/handoff/
 * 022-document-pipeline.md`): consent gate first, then the (currently unbound) provider seam,
 * and an audit event written only for a send that actually happened.
 *
 * `ai.enabled` defaults to `false` (`docs/handoff/041-settings-module.md` — fail closed), which
 * would make every call in this file hit the new `disabledBySettings()` gate before ever reaching
 * the assertions below that this file already existed to make. Forcing it on via the env-fallback
 * config path (no `settings` row needed) keeps this file testing exactly what it tested before
 * that gate existed — see `tests/Feature/Domain/Documents/RequestExternalDocumentExtractionSettingsTest.php`
 * for the settings-gating behaviour itself.
 */
beforeEach(function (): void {
    config(['settings.ai.env_fallback.enabled' => true]);

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );
});

it('is bound to NullDocumentAiProvider by default — no real provider is chosen yet', function (): void {
    expect(app(ExternalDocumentAiProvider::class)::class)->toBe(NullDocumentAiProvider::class);
});

it('refuses to call any provider when the subject has not consented, and writes no audit event', function (): void {
    $document = Document::factory()->create();

    $this->app->instance(ExternalDocumentAiProvider::class, new class () implements ExternalDocumentAiProvider {
        public function sendForExtraction(DocumentAiRequest $request): DocumentAiResponse
        {
            throw new RuntimeException('must never be called without consent');
        }
    });

    $action = app(RequestExternalDocumentExtraction::class);

    expect(fn () => $action($document, UntrustedDocumentText::wrap('some extracted text')))->toThrow(AiConsentRequiredException::class);
    expect(AuditEvent::query()->count())->toBe(0);
});

it('propagates "provider not configured" once consent exists, and writes no audit event', function (): void {
    $document = Document::factory()->create();
    app(DocumentAiConsentGate::class)->grant($document->tax_subject_id);

    $action = app(RequestExternalDocumentExtraction::class);

    expect(fn () => $action($document, UntrustedDocumentText::wrap('some extracted text')))
        ->toThrow(ExternalDocumentAiProviderNotConfiguredException::class);
    expect(AuditEvent::query()->count())->toBe(0);
});

it('writes exactly one audit event, naming the document and provider, only after a real send succeeds', function (): void {
    $document = Document::factory()->create();
    app(DocumentAiConsentGate::class)->grant($document->tax_subject_id);

    $this->app->instance(ExternalDocumentAiProvider::class, new class () implements ExternalDocumentAiProvider {
        public function sendForExtraction(DocumentAiRequest $request): DocumentAiResponse
        {
            return new DocumentAiResponse('acme-ai', 'acme-extractor', '1.0.0', ['field' => 'value'], '0.8800');
        }
    });

    $extraction = app(RequestExternalDocumentExtraction::class)($document, UntrustedDocumentText::wrap('some extracted text'));

    expect($extraction->model_provider)->toBe('acme-ai');

    $event = AuditEvent::query()->where('event_type', 'ExternalDocumentAiSendCompleted')->sole();
    expect($event->subject_id)->toBe($document->tax_subject_id)
        ->and($event->resource_id)->toBe($document->id);
});
