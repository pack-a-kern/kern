<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\IdCard\DataTransferObjects;

/**
 * What a model made of one identity card: the fields it could read, and where the photograph is.
 *
 * Every field is nullable because a field the model could not read confidently is null, not a
 * guess (ADR 0028). Nothing here is ever logged or put in an audit payload — it is a person's CNP,
 * name and address. It exists only to pre-fill the add-taxpayer form, which the user then confirms;
 * it is never written to the database as it stands (ADR 0005).
 */
final readonly class IdCardReading
{
    public function __construct(
        public ?string $cnp,
        public ?string $lastName,
        public ?string $firstName,
        public ?string $address,
        public ?string $series,
        public ?string $number,
        public ?string $validUntil,
        public ?IdCardFaceBox $faceBox,
    ) {
    }

    /**
     * Build a reading from the JSON object a model returned. Each value is taken only if it is a
     * non-empty string; the model is not trusted to have returned the right shape.
     *
     * @param  array<string, mixed>  $json
     */
    public static function fromModel(array $json): self
    {
        return new self(
            cnp: self::text($json['cnp'] ?? null),
            lastName: self::text($json['last_name'] ?? null),
            firstName: self::text($json['first_name'] ?? null),
            address: self::text($json['address'] ?? null),
            series: self::text($json['series'] ?? null),
            number: self::text($json['number'] ?? null),
            validUntil: self::text($json['valid_until'] ?? null),
            faceBox: IdCardFaceBox::fromModel($json['face_box'] ?? null),
        );
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * What the browser is handed to pre-fill the form and crop the avatar. The shape the model was
     * asked for, so a field left unread arrives explicitly null rather than missing.
     *
     * @return array{
     *     cnp: ?string, last_name: ?string, first_name: ?string, address: ?string,
     *     series: ?string, number: ?string, valid_until: ?string,
     *     face_box: array{x: float, y: float, w: float, h: float}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'cnp' => $this->cnp,
            'last_name' => $this->lastName,
            'first_name' => $this->firstName,
            'address' => $this->address,
            'series' => $this->series,
            'number' => $this->number,
            'valid_until' => $this->validUntil,
            'face_box' => $this->faceBox?->toArray(),
        ];
    }
}
