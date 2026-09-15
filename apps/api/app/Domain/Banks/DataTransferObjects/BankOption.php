<?php

declare(strict_types=1);

namespace App\Domain\Banks\DataTransferObjects;

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
    ) {
    }

    /** @return array{id: string, name: string, code: string|null, country_code: string, active: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'country_code' => $this->countryCode,
            'active' => $this->active,
        ];
    }
}
