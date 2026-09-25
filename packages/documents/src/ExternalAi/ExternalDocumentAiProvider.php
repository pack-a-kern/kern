<?php

declare(strict_types=1);

namespace Kern\Documents\ExternalAi;

use Kern\Documents\ExternalAi\DataTransferObjects\DocumentAiRequest;
use Kern\Documents\ExternalAi\DataTransferObjects\DocumentAiResponse;
use Kern\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderNotConfiguredException;

/**
 * The provider boundary ADR 0005 gates behind consent + audit. No implementation is bound in
 * production (`docs/STATUS.md` blocked item #3 — provider, region and DPA not yet chosen) — see
 * `NullDocumentAiProvider`, the concrete "unimplemented seam" this task was asked to build instead
 * of guessing a provider.
 */
interface ExternalDocumentAiProvider
{
    /** @throws ExternalDocumentAiProviderNotConfiguredException if no real provider is wired. */
    public function sendForExtraction(DocumentAiRequest $request): DocumentAiResponse;
}
