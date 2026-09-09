<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\TaxKnowledge\CandidateExtraction\CandidateCitationVerifierTest;

use App\Domain\TaxKnowledge\CandidateExtraction\CandidateChangeSetParser;
use App\Domain\TaxKnowledge\CandidateExtraction\CandidateCitationVerifier;
use App\Domain\TaxKnowledge\CandidateExtraction\Exceptions\UnknownSourceReferenceException;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Models\SourceSection;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

/**
 * @return array<string, mixed>
 */
function payloadFor(string $documentVersionId, string $sectionId): array
{
    return [
        'document_version_id' => $documentVersionId,
        'candidate_changes' => [
            [
                'topic' => 'CASS',
                'subject_types' => ['PF'],
                'income_categories' => [],
                'effective_from' => '2026-01-01',
                'effective_until' => null,
                'parameter_changes' => [],
                'rule_change_summary' => 'Synthetic test change.',
                'citations' => [
                    ['section_id' => $sectionId, 'quote_hash' => str_repeat('b', 64)],
                ],
                'confidence' => 0.8,
            ],
        ],
    ];
}

it('passes when the document version and every cited section genuinely exist and match', function (): void {
    $version = SourceDocumentVersion::factory()->create();
    $section = SourceSection::factory()->create(['document_version_id' => $version->id]);

    $set = CandidateChangeSetParser::fromArray(payloadFor($version->id, $section->id));

    app(CandidateCitationVerifier::class)->verify($set);
})->throwsNoExceptions();

it('rejects an unknown document_version_id', function (): void {
    $section = SourceSection::factory()->create();

    $set = CandidateChangeSetParser::fromArray(payloadFor('00000000-0000-0000-0000-000000000000', $section->id));

    app(CandidateCitationVerifier::class)->verify($set);
})->throws(UnknownSourceReferenceException::class);

it('rejects an unknown cited section_id', function (): void {
    $version = SourceDocumentVersion::factory()->create();

    $set = CandidateChangeSetParser::fromArray(payloadFor($version->id, '00000000-0000-0000-0000-000000000000'));

    app(CandidateCitationVerifier::class)->verify($set);
})->throws(UnknownSourceReferenceException::class);

it('rejects a citation whose section belongs to a different document version', function (): void {
    $version = SourceDocumentVersion::factory()->create();
    $otherVersion = SourceDocumentVersion::factory()->create();
    $sectionFromOtherVersion = SourceSection::factory()->create(['document_version_id' => $otherVersion->id]);

    $set = CandidateChangeSetParser::fromArray(payloadFor($version->id, $sectionFromOtherVersion->id));

    app(CandidateCitationVerifier::class)->verify($set);
})->throws(UnknownSourceReferenceException::class);
