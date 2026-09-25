<?php

declare(strict_types=1);

namespace Kern\Documents\ExternalAi\DataTransferObjects;

/**
 * A document's own bytes, ready to be attached to a provider request.
 *
 * Exists because a photograph has no text to send. A PDF is read locally by PyMuPDF and only the
 * text ever leaves; an image has to be looked at, so the image itself goes — which is precisely the
 * external send ADR 0005 gates behind consent, and why this type is deliberately explicit rather
 * than a `string $bytes` slipped into an existing field.
 *
 * Base64 at construction rather than on demand: both dialects need it that way, and encoding once
 * keeps the decoded bytes from being held alongside the encoded copy.
 */
final readonly class DocumentContent
{
    private function __construct(
        public string $mimeType,
        public string $base64,
        public int $sizeBytes,
    ) {
    }

    public static function fromBytes(string $bytes, string $mimeType): self
    {
        return new self($mimeType, base64_encode($bytes), strlen($bytes));
    }

    /**
     * What the dialect needs to build an image block.
     *
     * @return array{mime_type: string, base64: string}
     */
    public function toDialectImage(): array
    {
        return ['mime_type' => $this->mimeType, 'base64' => $this->base64];
    }
}
