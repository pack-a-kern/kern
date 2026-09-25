<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Support;

use Kern\TaxRomania\Subjects\Enums\Sex;

/**
 * What a CNP says about the person it identifies.
 *
 * Sex and residency are separate fields because they are separate facts: digits 7 and 8 mean "male"
 * and "female" *and* "foreign citizen resident here". Folding those into one enum makes it
 * impossible to ask either question without also answering the other.
 */
final readonly class CnpDetails
{
    public function __construct(
        public Sex $sex,
        /** True for leading digits 7 and 8: a foreign citizen holding Romanian residence. */
        public bool $foreignResident,
        /** `YYYY-MM-DD`, derived from the CNP's own digits. */
        public string $birthDate,
        /** The county's numeric code as the CNP writes it, `01`–`52`. */
        public string $countyNumber,
        /** The same county in the letter code the rest of this application uses, e.g. `CJ`. */
        public string $countyCode,
        /**
         * The locality the CNP actually names, or null when it names none.
         *
         * Only Bucharest gets one: county codes 41–46 are its six sectors, which are localities in
         * their own right. Everywhere else the code names a county and says nothing about the town,
         * so the form still has to ask.
         */
        public ?string $locality,
        public string $serial,
    ) {
    }

    /** @return array<string, string|bool|null> */
    public function toArray(): array
    {
        return [
            'sex' => $this->sex->value,
            'foreign_resident' => $this->foreignResident,
            'birth_date' => $this->birthDate,
            'county_number' => $this->countyNumber,
            'county_code' => $this->countyCode,
            'locality' => $this->locality,
        ];
    }
}
