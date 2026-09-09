<?php

declare(strict_types=1);

namespace App\Domain\Documents\Extraction;

use App\Domain\Documents\Extraction\DataTransferObjects\NativeTextExtractionResult;
use App\Domain\Documents\Extraction\Exceptions\DocumentRejectedByExtractionException;
use App\Domain\Documents\Extraction\Exceptions\TextExtractionUnavailableException;

/**
 * Calls the `apps/processing` native-PDF-text-extraction seam
 * (`docs/handoff/019-pdf-text-extraction.md`'s `processing.documents.extract_document`, exposed
 * over HTTP by `docs/handoff/024-extraction-endpoint.md`) over the internal network. See
 * `HttpTextExtractionGateway`'s own docblock for the exact, verified contract.
 */
interface TextExtractionGateway
{
    /**
     * @param resource $pdfStream a readable stream of the (already AV-scanned) PDF bytes. The
     *                            caller retains ownership and must close it.
     *
     * @throws TextExtractionUnavailableException     if the service itself could not be reached,
     *                                                 is misconfigured, or returned a response
     *                                                 that did not match the expected contract —
     *                                                 retry.
     * @throws DocumentRejectedByExtractionException if the service rejected this specific
     *                                                 document for a documented reason — do not
     *                                                 retry.
     */
    public function extractNativeText($pdfStream): NativeTextExtractionResult;
}
