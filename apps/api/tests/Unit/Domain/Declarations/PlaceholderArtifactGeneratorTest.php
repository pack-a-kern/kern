<?php

declare(strict_types=1);

use App\Domain\Declarations\ArtifactGeneration\PlaceholderArtifactGenerator;

/**
 * `docs/AGENTS.md` §2: a placeholder must never read as real. This test proves the generator's
 * output is unmistakably marked as such, and that its own `sha256` is honest (matches the content
 * it actually returned).
 */
it('marks its output unambiguously as a placeholder, never a real ANAF artifact', function (): void {
    $artifact = (new PlaceholderArtifactGenerator())->generate(['assessment_id' => 'synthetic-1']);

    expect($artifact->format)->toBe('PLACEHOLDER_NOT_ANAF_FORMAT')
        ->and($artifact->content)->toContain('PLACEHOLDER_NOT_ANAF_FORMAT')
        ->and($artifact->content)->toContain('must never be filed with ANAF')
        ->and($artifact->sha256)->toBe(hash('sha256', $artifact->content));
});

it('embeds the exact snapshot payload it was given', function (): void {
    $payload = ['assessment_id' => 'synthetic-42', 'declaration_kind' => 'ORIGINAL'];

    $artifact = (new PlaceholderArtifactGenerator())->generate($payload);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($artifact->content, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['snapshot'])->toBe($payload);
});
