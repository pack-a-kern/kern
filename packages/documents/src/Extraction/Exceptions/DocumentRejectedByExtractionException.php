<?php

declare(strict_types=1);

namespace Kern\Documents\Extraction\Exceptions;

use Kern\Documents\Enums\RejectionReason;
use RuntimeException;

/**
 * The text-extraction service looked at this specific document and rejected it for one of its
 * closed set of documented reasons (`docs/handoff/024-extraction-endpoint.md`) — an encrypted
 * PDF, too many pages, a malformed file, and so on. Unlike
 * {@see TextExtractionUnavailableException}, this is never a reason to retry: the same document
 * will fail the same way every time. `Kern\Documents\Jobs\ProcessScannedDocument` catches
 * this and moves the document straight to `DocumentStatus::REJECTED` with the closed
 * {@see RejectionReason}, the same fail-fast, no-retry treatment
 * `Kern\Documents\FileSecurity\Exceptions\FileSecurityException` already gets for upload-time
 * rejections — never the message text, which exists for diagnostics only.
 */
final class DocumentRejectedByExtractionException extends RuntimeException
{
    private function __construct(string $message, private readonly RejectionReason $reason)
    {
        parent::__construct($message);
    }

    public static function forReason(RejectionReason $reason, string $message): self
    {
        return new self($message, $reason);
    }

    public function reason(): RejectionReason
    {
        return $this->reason;
    }
}
