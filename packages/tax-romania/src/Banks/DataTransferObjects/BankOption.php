<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\DataTransferObjects;

/** A bank as a form offers it. */
final readonly class BankOption
{
    public function __construct(
        public string $id,
        public string $name,
        /** The 4-letter code from the BIC, or null where GLEIF maps none. */
        public ?string $code,
        public string $countryCode,
        public bool $active,
        /** The group it belongs to — "Erste Group Bank" for BCR — or null. */
        public ?string $group,
        /** This application's address for the bank's logo, or null where it has none: draw its initials. */
        public ?string $logoUrl,
    ) {
    }

    /** @return array{id: string, name: string, code: string|null, country_code: string, active: bool, group: string|null, logo_url: string|null} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'country_code' => $this->countryCode,
            'active' => $this->active,
            'group' => $this->group,
            'logo_url' => $this->logoUrl,
        ];
    }
}
