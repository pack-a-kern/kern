<?php

declare(strict_types=1);

namespace App\Domain\Documents\ExternalAi;

use App\Domain\Documents\ExternalAi\DataTransferObjects\DocumentAiRequest;
use App\Domain\Documents\ExternalAi\DataTransferObjects\DocumentAiResponse;
use App\Domain\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderNotConfiguredException;

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
