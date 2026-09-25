<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\DataTransferObjects;

use Kern\TaxRomania\Declarations\Enums\FormVersionStatus;

final readonly class RegisterFormVersionInput
{
    /**
     * @param array<string, mixed>|null $schemaJson see `form_versions` migration's docblock for
     *                                               the expected `{"sections": [...]}` shape;
     *                                               validated for well-formedness, never fiscal
     *                                               correctness, if not null.
     */
    public function __construct(
        public string $formDefinitionId,
        public string $versionCode,
        public string $applicableFrom,
        public ?string $applicableUntil = null,
        public ?int $taxYearFrom = null,
        public ?int $taxYearTo = null,
        public ?string $schemaObjectPath = null,
        public ?string $instructionsDocumentVersionId = null,
        public ?array $schemaJson = null,
        public FormVersionStatus $status = FormVersionStatus::DRAFT,
    ) {
    }
}
