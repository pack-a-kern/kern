<?php

declare(strict_types=1);

namespace Kern\Documents\ExternalAi;

use Kern\Documents\ExternalAi\DataTransferObjects\DocumentAiRequest;
use Kern\Documents\ExternalAi\DataTransferObjects\DocumentAiResponse;
use Kern\Documents\ExternalAi\Exceptions\ExternalDocumentAiProviderNotConfiguredException;

/**
 * The container's default `ExternalDocumentAiProvider` binding
 * (`Kern\Documents\DocumentsServiceProvider`) until a real provider is chosen. Deliberately
 * a concrete, named Null Object rather than leaving the interface unbound: an unbound interface
 * fails with a generic `BindingResolutionException` naming a constructor argument, which is a
 * worse error for whoever eventually wires up the real provider than this class's explicit,
 * documented failure. This is not speculative scaffolding for a feature that does not exist — the
 * feature (send this document for external extraction) exists and is called; only the concrete
 * integration is pending a business decision.
 */
final class NullDocumentAiProvider implements ExternalDocumentAiProvider
{
    public function sendForExtraction(DocumentAiRequest $request): DocumentAiResponse
    {
        throw ExternalDocumentAiProviderNotConfiguredException::noProviderBound();
    }
}
