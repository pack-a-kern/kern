<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\TaxKnowledge\CandidateExtraction\CandidateChangeSetParserTest;

use App\Domain\TaxKnowledge\CandidateExtraction\CandidateChangeSetParser;
use App\Domain\TaxKnowledge\CandidateExtraction\Exceptions\InvalidCandidateChangeSetException;

/**
 * Pure unit tests of `BLUEPRINT.md` §9.2's contract — no database, no LLM, no network. The document
 * ids / section ids below are synthetic UUID-shaped strings, not references to real rows;
 * existence checking is {@see \App\Domain\TaxKnowledge\CandidateExtraction\CandidateCitationVerifier}'s
 * job (tested separately, against a real database).
 *
 * The precise `@return` shape (rather than a loose `array<string, mixed>`) is deliberate: it lets
 * every test below mutate a specific nested field (`$payload['candidate_changes'][0]['citations']
 * = []`, etc.) with PHPStan still able to type-check the offset access, instead of every such
 * access degrading to "indexing mixed".
 *
 * @return array{
 *     document_version_id: string,
 *     candidate_changes: list<array{
 *         topic: string,
 *         subject_types: mixed,
 *         income_categories: mixed,
 *         effective_from: mixed,
 *         effective_until: mixed,
 *         parameter_changes: mixed,
 *         rule_change_summary: mixed,
 *         citations: list<array{section_id: mixed, quote_hash: mixed}>,
 *         confidence: mixed,
 *     }>,
 * }
 */
function validPayload(): array
{
    return [
        'document_version_id' => '11111111-1111-1111-1111-111111111111',
        'candidate_changes' => [
            [
                'topic' => 'CASS',
                'subject_types' => ['PF'],
                'income_categories' => ['INVESTMENT'],
                'effective_from' => '2026-01-01',
                'effective_until' => null,
                'parameter_changes' => [],
                'rule_change_summary' => 'CASS threshold for investment income changes.',
                'citations' => [
                    ['section_id' => '22222222-2222-2222-2222-222222222222', 'quote_hash' => str_repeat('a', 64)],
                ],
                'confidence' => 0.97,
            ],
        ],
    ];
}

it('parses a valid candidate change set from the BLUEPRINT.md §9.2 example shape', function (): void {
    $set = CandidateChangeSetParser::fromArray(validPayload());

    expect($set->documentVersionId)->toBe('11111111-1111-1111-1111-111111111111')
        ->and($set->candidateChanges)->toHaveCount(1);

    $change = $set->candidateChanges[0];
    expect($change->topic)->toBe('CASS')
        ->and($change->subjectTypes)->toBe(['PF'])
        ->and($change->incomeCategories)->toBe(['INVESTMENT'])
        ->and($change->effectiveFrom->format('Y-m-d'))->toBe('2026-01-01')
        ->and($change->effectiveUntil)->toBeNull()
        ->and($change->confidence)->toBe(0.97)
        ->and($change->citations)->toHaveCount(1)
        ->and($change->citations[0]->sectionId)->toBe('22222222-2222-2222-2222-222222222222')
        ->and($change->citations[0]->quoteHash)->toBe(str_repeat('a', 64));
});

it('accepts an empty candidate_changes array — "no changes found" is a legitimate result', function (): void {
    $payload = validPayload();
    $payload['candidate_changes'] = [];

    $set = CandidateChangeSetParser::fromArray($payload);

    expect($set->candidateChanges)->toBe([]);
});

it('rejects malformed JSON', function (): void {
    CandidateChangeSetParser::fromJson('{not valid json');
})->throws(InvalidCandidateChangeSetException::class);

it('rejects a non-object top level', function (): void {
    CandidateChangeSetParser::fromJson('"just a string"');
})->throws(InvalidCandidateChangeSetException::class);

it('rejects a missing document_version_id', function (): void {
    $payload = validPayload();
    unset($payload['document_version_id']);

    CandidateChangeSetParser::fromArray($payload);
})->throws(InvalidCandidateChangeSetException::class);

it('rejects a candidate change with zero citations', function (): void {
    $payload = validPayload();
    $payload['candidate_changes'][0]['citations'] = [];

    CandidateChangeSetParser::fromArray($payload);
})->throws(InvalidCandidateChangeSetException::class, 'must be a non-empty array');

it('rejects a candidate change missing citations entirely', function (): void {
    $payload = validPayload();
    unset($payload['candidate_changes'][0]['citations']);

    CandidateChangeSetParser::fromArray($payload);
})->throws(InvalidCandidateChangeSetException::class);

it('rejects a citation with a malformed quote_hash', function (): void {
    $payload = validPayload();
    $payload['candidate_changes'][0]['citations'][0]['quote_hash'] = 'not-a-hash';

    CandidateChangeSetParser::fromArray($payload);
})->throws(InvalidCandidateChangeSetException::class, 'quote_hash');

it('rejects a citation missing section_id', function (): void {
    $payload = validPayload();
    unset($payload['candidate_changes'][0]['citations'][0]['section_id']);

    CandidateChangeSetParser::fromArray($payload);
})->throws(InvalidCandidateChangeSetException::class);

it('rejects confidence above 1', function (): void {
    $payload = validPayload();
    $payload['candidate_changes'][0]['confidence'] = 1.5;

    CandidateChangeSetParser::fromArray($payload);
})->throws(InvalidCandidateChangeSetException::class, 'confidence');

it('rejects confidence below 0', function (): void {
    $payload = validPayload();
    $payload['candidate_changes'][0]['confidence'] = -0.1;

    CandidateChangeSetParser::fromArray($payload);
})->throws(InvalidCandidateChangeSetException::class, 'confidence');

it('rejects a non-numeric confidence', function (): void {
    $payload = validPayload();
    $payload['candidate_changes'][0]['confidence'] = 'high';

    CandidateChangeSetParser::fromArray($payload);
})->throws(InvalidCandidateChangeSetException::class);

it('rejects a missing effective_from', function (): void {
    $payload = validPayload();
    unset($payload['candidate_changes'][0]['effective_from']);

    CandidateChangeSetParser::fromArray($payload);
})->throws(InvalidCandidateChangeSetException::class);

it('rejects effective_until before effective_from', function (): void {
    $payload = validPayload();
    $payload['candidate_changes'][0]['effective_from'] = '2026-06-01';
    $payload['candidate_changes'][0]['effective_until'] = '2026-01-01';

    CandidateChangeSetParser::fromArray($payload);
})->throws(InvalidCandidateChangeSetException::class, 'precedes');

it('rejects a malformed date string', function (): void {
    $payload = validPayload();
    $payload['candidate_changes'][0]['effective_from'] = '01/01/2026';

    CandidateChangeSetParser::fromArray($payload);
})->throws(InvalidCandidateChangeSetException::class);

it('rejects an empty rule_change_summary', function (): void {
    $payload = validPayload();
    $payload['candidate_changes'][0]['rule_change_summary'] = '';

    CandidateChangeSetParser::fromArray($payload);
})->throws(InvalidCandidateChangeSetException::class);

it('rejects a non-array subject_types', function (): void {
    $payload = validPayload();
    $payload['candidate_changes'][0]['subject_types'] = 'PF';

    CandidateChangeSetParser::fromArray($payload);
})->throws(InvalidCandidateChangeSetException::class);

it('rejects a non-array parameter_changes', function (): void {
    $payload = validPayload();
    $payload['candidate_changes'][0]['parameter_changes'] = 'none';

    CandidateChangeSetParser::fromArray($payload);
})->throws(InvalidCandidateChangeSetException::class);

it('never sets or exposes an APPROVED-style status — the contract has no such field', function (): void {
    $set = CandidateChangeSetParser::fromArray(validPayload());

    // The parsed DTO tree has no `status` property anywhere — asserted structurally: converting
    // to an array of public properties must not contain the word "APPROVED" in any form.
    $encoded = json_encode($set);

    expect($encoded)->not->toBeFalse();
    expect(mb_strtoupper((string) $encoded))->not->toContain('APPROVED');
});
