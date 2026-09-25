<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\DataTransferObjects;

/**
 * The correctable half of a taxpayer's registration.
 *
 * Every field is nullable and null means "leave it alone" rather than "clear it": a form that sends
 * only what a person touched cannot accidentally blank an address it never showed them.
 */
final readonly class UpdatePfSubjectInput
{
    public function __construct(
        public ?string $displayName = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $countyCode = null,
        public ?string $locality = null,
        public ?string $address = null,
    ) {
    }
}
