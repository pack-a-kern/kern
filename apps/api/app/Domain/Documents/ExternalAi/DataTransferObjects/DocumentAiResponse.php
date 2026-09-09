<?php

declare(strict_types=1);

namespace App\Domain\Documents\ExternalAi\DataTransferObjects;

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
        public string $confidenceDecimal,
    ) {
    }
}
