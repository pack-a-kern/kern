<?php

declare(strict_types=1);

namespace App\Domain\Documents\ExternalAi\DataTransferObjects;

use App\Domain\Documents\Enums\DocumentType;

/**
 * What a real provider integration would return. `result` must already be validated against a
 * strict JSON schema by the concrete provider adapter before this DTO is constructed —
 * `BLUEPRINT.md` §14.4 "LLM output invalid schema = failure, nu best-effort save" — this DTO's
 * existence is not itself that validation.
 */
final readonly class DocumentAiResponse
{
    /** @param array<array-key, mixed> $result */
    public function __construct(
        public string $modelProvider,
        public string $modelName,
        public string $schemaVersion,
        public array $result,
        /** Null where the model reported none — never a placeholder, which a screen draws as real. */
        public ?string $confidenceDecimal,
        /**
         * What the document turned out to be.
         *
         * Carried back because the model is what decided it: a document is uploaded untyped, and
         * this reading is the first thing that knows whether it is an invoice or a statement.
         */
        public DocumentType $documentType,
        /** Exactly what the model returned, kept so a person can be shown it. */
        public ?string $rawReply = null,
        /** How long the call took and what it spent — the provider's own numbers, not estimates. */
        public ?int $durationMs = null,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
    ) {
    }
}
