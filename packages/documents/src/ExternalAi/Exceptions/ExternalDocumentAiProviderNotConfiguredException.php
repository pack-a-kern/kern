<?php

declare(strict_types=1);

namespace Kern\Documents\ExternalAi\Exceptions;

use Kern\Documents\Enums\AiExtractionOutcome;
use Kern\Documents\ExternalAi\ExplainsAiExtractionOutcome;
use RuntimeException;

/**
 * Thrown by `NullDocumentAiProvider` — the concrete, container-bound answer to "no external
 * document-AI provider is configured" (`docs/STATUS.md` blocked item #3: provider, region and DPA
 * not yet chosen). Never caught and papered over with a fallback send; a caller catching this must
 * leave the document exactly where it was (`BLUEPRINT.md` §34 "no auto-submit fără review" extends
 * here as "no auto-fallback for an unmade business decision").
 */
final class ExternalDocumentAiProviderNotConfiguredException extends RuntimeException implements ExplainsAiExtractionOutcome
{
    private AiExtractionOutcome $outcome = AiExtractionOutcome::NOT_SENT_PROVIDER_UNCONFIGURED;

    public static function noProviderBound(): self
    {
        return new self(
            'No external document-AI provider is configured (docs/STATUS.md blocked item #3: ' .
            'provider, region and DPA not yet chosen). This document cannot be sent for external ' .
            'extraction until that decision is made.',
        );
    }

    /**
     * Thrown by `Kern\Documents\ExternalAi\RequestExternalDocumentExtraction` when the
     * system-wide `ai.enabled` setting (`docs/handoff/041-settings-module.md`,
     * `Kern\Platform\Settings\Services\ResolveAiProviderCredentials`) resolves to `false` — a
     * distinct, administrator-controlled off switch from `noProviderBound()`'s "nobody has
     * implemented a real provider yet" state. Both are visible, expected, non-retryable states,
     * never confused with a transient failure.
     */
    public static function disabledBySettings(): self
    {
        $exception = new self(
            'External document AI is disabled (ai.enabled=false in system settings). An ' .
            'administrator can enable it via PUT /api/v1/settings/ai.',
        );

        $exception->outcome = AiExtractionOutcome::NOT_SENT_PROVIDER_DISABLED;

        return $exception;
    }

    public function outcome(): AiExtractionOutcome
    {
        return $this->outcome;
    }
}
