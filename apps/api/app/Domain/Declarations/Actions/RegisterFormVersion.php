<?php

declare(strict_types=1);

namespace App\Domain\Declarations\Actions;

use App\Domain\Declarations\DataTransferObjects\RegisterFormVersionInput;
use App\Domain\Declarations\Exceptions\InvalidFormVersionDateRangeException;
use App\Domain\Declarations\Models\FormVersion;
use App\Domain\Declarations\Services\FormSchemaValidator;
use DateTimeImmutable;

/**
 * Registers one `form_versions` row. Validates date-range consistency eagerly and, if
 * `schemaJson` is supplied, its structural well-formedness (`App\Domain\Declarations\Services\
 * FormSchemaValidator`) — never its fiscal correctness, which no agent can verify without a real
 * D212 specification (`docs/AGENTS.md` §2).
 */
final readonly class RegisterFormVersion
{
    public function __construct(
        private FormSchemaValidator $schemaValidator,
    ) {
    }

    public function __invoke(RegisterFormVersionInput $input): FormVersion
    {
        $this->assertValidDateRanges($input);

        if ($input->schemaJson !== null) {
            $this->schemaValidator->assertWellFormed($input->schemaJson);
        }

        return FormVersion::create([
            'form_definition_id' => $input->formDefinitionId,
            'version_code' => $input->versionCode,
            'applicable_from' => $input->applicableFrom,
            'applicable_until' => $input->applicableUntil,
            'tax_year_from' => $input->taxYearFrom,
            'tax_year_to' => $input->taxYearTo,
            'schema_object_path' => $input->schemaObjectPath,
            'instructions_document_version_id' => $input->instructionsDocumentVersionId,
            'schema_json' => $input->schemaJson,
            'status' => $input->status,
        ]);
    }

    private function assertValidDateRanges(RegisterFormVersionInput $input): void
    {
        if ($input->applicableUntil !== null) {
            $from = new DateTimeImmutable($input->applicableFrom);
            $until = new DateTimeImmutable($input->applicableUntil);

            if (($until <=> $from) <= 0) {
                throw InvalidFormVersionDateRangeException::applicableUntilBeforeFrom(
                    $input->applicableFrom,
                    $input->applicableUntil,
                );
            }
        }

        if ($input->taxYearFrom !== null && $input->taxYearTo !== null && $input->taxYearTo < $input->taxYearFrom) {
            throw InvalidFormVersionDateRangeException::taxYearToBeforeFrom($input->taxYearFrom, $input->taxYearTo);
        }
    }
}
