<?php

declare(strict_types=1);

namespace Tests\Feature\Documents\HttpTextExtractionGatewayTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kern\Documents\Extraction\Exceptions\TextExtractionUnavailableException;
use Kern\Documents\Extraction\TextExtractionGateway;
use RuntimeException;

/**
 * `docs/handoff/024-extraction-endpoint.md`'s real, verified `POST
 * /v1/documents/extract-text` contract, exercised directly against `HttpTextExtractionGateway`
 * (bound by `Kern\Documents\DocumentsServiceProvider`) rather than only through
 * `ProcessScannedDocument` — the gateway's own `parse()` contract validation is the unit under
 * test here.
 */
beforeEach(function (): void {
    config(['services.processing.auth_token' => 'test-shared-secret']);
});

/** @return resource */
function pdfStream(string $content = '%PDF-1.4 fixture')
{
    $stream = fopen('php://temp', 'w+b');

    if ($stream === false) {
        throw new RuntimeException('Unable to open a memory stream.');
    }

    fwrite($stream, $content);
    rewind($stream);

    return $stream;
}

it('parses the real per-page quality_score and the structural untrusted-text wrapper', function (): void {
    Http::fake(['*/v1/documents/extract-text' => Http::response([
        'page_count' => 2,
        'needs_ocr' => false,
        'overall_decision' => 'native_text_sufficient',
        'pages_needing_ocr' => [],
        'pages' => [
            [
                'page_number' => 1,
                'needs_ocr' => false,
                'quality_score' => '0.9800',
                'quality_signals' => ['non_whitespace_char_count' => 10],
                'text' => ['kind' => 'untrusted_document_text', 'content' => 'first page'],
            ],
            [
                'page_number' => 2,
                'needs_ocr' => false,
                'quality_score' => '0.6500',
                'quality_signals' => ['non_whitespace_char_count' => 5],
                'text' => ['kind' => 'untrusted_document_text', 'content' => 'second page'],
            ],
        ],
    ])]);

    $result = app(TextExtractionGateway::class)->extractNativeText(pdfStream());

    expect($result->pageCount)->toBe(2)
        ->and($result->needsOcr)->toBeFalse()
        ->and($result->pages[1]->qualityScoreDecimal)->toBe('0.9800')
        ->and($result->pages[2]->qualityScoreDecimal)->toBe('0.6500')
        ->and($result->pages[1]->text->reveal())->toBe('first page');
});

it('rejects a response whose text field is still a bare string (handoff 022\'s old, wrong shape)', function (): void {
    Http::fake(['*/v1/documents/extract-text' => Http::response([
        'page_count' => 1,
        'needs_ocr' => false,
        'pages' => [['page_number' => 1, 'quality_score' => '0.9', 'text' => 'bare string, not the {"kind": ...} wrapper']],
    ])]);

    expect(fn () => app(TextExtractionGateway::class)->extractNativeText(pdfStream()))
        ->toThrow(TextExtractionUnavailableException::class);
});

it('rejects a response whose text.kind is not "untrusted_document_text"', function (): void {
    Http::fake(['*/v1/documents/extract-text' => Http::response([
        'page_count' => 1,
        'needs_ocr' => false,
        'pages' => [['page_number' => 1, 'quality_score' => '0.9', 'text' => ['kind' => 'plain_text', 'content' => 'x']]],
    ])]);

    expect(fn () => app(TextExtractionGateway::class)->extractNativeText(pdfStream()))
        ->toThrow(TextExtractionUnavailableException::class);
});

it('rejects a response with the old handoff-022-shaped top-level quality_score and no per-page one', function (): void {
    Http::fake(['*/v1/documents/extract-text' => Http::response([
        'page_count' => 1,
        'needs_ocr' => false,
        'quality_score' => '0.9800',
        'pages' => [['page_number' => 1, 'text' => ['kind' => 'untrusted_document_text', 'content' => 'x']]],
    ])]);

    expect(fn () => app(TextExtractionGateway::class)->extractNativeText(pdfStream()))
        ->toThrow(TextExtractionUnavailableException::class);
});

it('sends the shared bearer token on every request', function (): void {
    Http::fake(['*/v1/documents/extract-text' => Http::response([
        'page_count' => 0, 'needs_ocr' => false, 'pages' => [],
    ])]);

    app(TextExtractionGateway::class)->extractNativeText(pdfStream());

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-shared-secret'));
});

it('fails closed without sending a request when no auth token is configured', function (): void {
    config(['services.processing.auth_token' => '']);

    Http::fake();

    expect(fn () => app(TextExtractionGateway::class)->extractNativeText(pdfStream()))
        ->toThrow(TextExtractionUnavailableException::class);

    Http::assertNothingSent();
});
