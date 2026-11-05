<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\Logos;

/** One bank's logo file, as the folder's index found it. */
final readonly class BankLogo
{
    public function __construct(
        /** The file's path inside the chosen folder — `BTRLRO22.svg` or `RO/BTRLRO22.svg`. */
        public string $file,
        public string $mimeType,
        /** Changes whenever the file's content does: the logo's address and its etag carry it. */
        public string $fingerprint,
    ) {
    }

    /** @return array{file: string, mime_type: string, fingerprint: string} */
    public function toArray(): array
    {
        return ['file' => $this->file, 'mime_type' => $this->mimeType, 'fingerprint' => $this->fingerprint];
    }

    /** @param array{file: string, mime_type: string, fingerprint: string} $row */
    public static function fromArray(array $row): self
    {
        return new self($row['file'], $row['mime_type'], $row['fingerprint']);
    }
}
