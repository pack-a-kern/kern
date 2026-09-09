<?php

declare(strict_types=1);

namespace App\Domain\Declarations\DataTransferObjects;

/**
 * The output of `App\Domain\Declarations\Contracts\DeclarationArtifactGenerator::generate()`.
 * `$format` names which generator produced this — see `App\Domain\Declarations\ArtifactGeneration\
 * PlaceholderArtifactGenerator`'s docblock for why every value this codebase ships is explicitly
 * marked as not an ANAF-accepted format (`docs/STATUS.md` blocked item 2;
 * `docs/reference/anaf-spv-integration.md`).
 */
final readonly class GeneratedArtifact
{
    public function __construct(
        public string $content,
        public string $mimeType,
        public string $sha256,
        public string $format,
    ) {
    }
}
