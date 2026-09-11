<?php

declare(strict_types=1);

namespace App\Domain\Documents\ExternalAi;

use App\Domain\Documents\Enums\AiExtractionOutcome;

/**
 * Implemented by every exception that ends a document's external-AI attempt, so the attempt's
 * result can be recorded without the caller having to know which exception it caught.
 *
 * The alternative — a `match` on exception class in the pipeline — puts the knowledge of what each
 * failure *means* somewhere other than the failure itself, and quietly falls out of date the first
 * time someone adds a case. Here the enum value travels with the exception that knows it.
 */
interface ExplainsAiExtractionOutcome
{
    public function outcome(): AiExtractionOutcome;
}
