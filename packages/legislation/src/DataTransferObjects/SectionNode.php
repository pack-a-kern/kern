<?php

declare(strict_types=1);

namespace Kern\Legislation\DataTransferObjects;

use Kern\Legislation\Enums\SectionType;
use stdClass;
use UnexpectedValueException;

/**
 * One section's place in a law — what it is, what it stands under, which heading — without its wording.
 *
 * What the reader builds a law's tree from. A consolidation of the Codul fiscal is some 7 000 sections,
 * and as Eloquent models their structure alone held 20 MB for each page read; as rows read straight
 * into this, under 5. The wording is read separately, for the sections a page shows.
 */
final readonly class SectionNode
{
    /** The columns {@see fromRow()} reads. */
    public const array COLUMNS = ['id', 'parent_id', 'section_type', 'citation_label', 'outline_id', 'amended_on'];

    public function __construct(
        public string $id,
        public ?string $parentId,
        public SectionType $type,
        public string $citation,
        public ?string $outlineId,
        /** As the database returns it; formatted only for the sections a page shows. */
        public ?string $amendedOn,
    ) {
    }

    public static function fromRow(stdClass $row): self
    {
        $columns = get_object_vars($row);

        return new self(
            id: self::required($columns, 'id'),
            parentId: self::optional($columns, 'parent_id'),
            type: SectionType::from(self::required($columns, 'section_type')),
            citation: self::required($columns, 'citation_label'),
            outlineId: self::optional($columns, 'outline_id'),
            amendedOn: self::optional($columns, 'amended_on'),
        );
    }

    /** @param  array<string, mixed>  $columns */
    private static function optional(array $columns, string $name): ?string
    {
        $value = $columns[$name] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /** @param  array<string, mixed>  $columns */
    private static function required(array $columns, string $name): string
    {
        return self::optional($columns, $name) ?? throw new UnexpectedValueException("A section row without {$name}.");
    }
}
