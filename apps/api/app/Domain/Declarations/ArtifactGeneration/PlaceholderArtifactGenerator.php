<?php

declare(strict_types=1);

namespace App\Domain\Declarations\ArtifactGeneration;

use App\Domain\Declarations\Contracts\DeclarationArtifactGenerator;
use App\Domain\Declarations\DataTransferObjects\GeneratedArtifact;

/**
 * **This is NOT a real, ANAF-accepted D212 artifact and must never be submitted as one.**
 *
 * ANAF publishes no documented D212 submission API and the portal's own accepted artifact format
 * has not been observed (`docs/reference/anaf-spv-integration.md`, `docs/STATUS.md` blocked item
 * 2). Inventing an XML schema, PDF field map, or submission envelope here would be exactly the
 * "output that looks real but isn't" `docs/AGENTS.md` §2 forbids. Instead, this generator produces
 * a plain, self-describing JSON document of the frozen snapshot payload — every value it contains
 * already exists in `declarations.snapshot_json`, formatted for human inspection only — and
 * labels its own `format` field `PLACEHOLDER_NOT_ANAF_FORMAT` so nothing downstream can mistake
 * it for a real filing artifact.
 *
 * Bound behind `App\Domain\Declarations\Contracts\DeclarationArtifactGenerator`
 * (`App\Domain\Declarations\DeclarationsServiceProvider`) — replace the binding, not this class,
 * once a real specification exists.
 */
final class PlaceholderArtifactGenerator implements DeclarationArtifactGenerator
{
    public const string FORMAT = 'PLACEHOLDER_NOT_ANAF_FORMAT';

    public function generate(array $snapshotPayload): GeneratedArtifact
    {
        $content = json_encode(
            [
                'format' => self::FORMAT,
                'warning' => 'This is a placeholder document, not a real ANAF submission artifact. '
                    . 'It must never be filed with ANAF. See docs/reference/anaf-spv-integration.md.',
                'snapshot' => $snapshotPayload,
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
        );

        return new GeneratedArtifact(
            content: $content,
            mimeType: 'application/json',
            sha256: hash('sha256', $content),
            format: self::FORMAT,
        );
    }
}
