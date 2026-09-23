<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters;

use App\Domain\TaxKnowledge\Enums\FiscalParameterValueType;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use InvalidArgumentException;

/**
 * The values looked for — `config/fiscal_parameters.php` — read once and checked, so the readers,
 * the law and the screen take typed definitions and never the raw configuration.
 *
 * A rule is its tax's: `RO.INCOME_TAX` declares the part of the law it is read from and `RATE` is
 * read there under the code `RO.INCOME_TAX.RATE`. The minimum wage stands outside them all, having
 * no question to put to this law: a Government decision sets it.
 */
final readonly class ParameterCatalog
{
    /** @var array<string, ParameterDefinition> */
    private array $definitions;

    public function __construct(private ConfigRepository $config)
    {
        $wage = $config->string('fiscal_parameters.minimum_wage.parameter');
        $definitions = [$wage => new ParameterDefinition(
            code: $wage,
            tax: $wage,
            type: FiscalParameterValueType::MONEY,
            subject: ParameterSubject::PERSON,
            lawWithin: null,
            question: null,
            answer: ParameterAnswer::LEI,
        )];

        foreach ($config->array('fiscal_parameters.taxes') as $tax => $entry) {
            if (! is_string($tax) || ! is_array($entry) || ! is_array($entry['rules'] ?? null)) {
                throw new InvalidArgumentException('fiscal_parameters.taxes: every entry is a tax, the part of the law it is read from, and its rules.');
            }

            $taxLaw = is_array($entry['law'] ?? null) ? $entry['law'] : [];

            foreach ($entry['rules'] as $name => $rule) {
                if (! is_string($name) || ! is_array($rule)) {
                    throw new InvalidArgumentException("fiscal_parameters.taxes.{$tax}.rules: every entry is a rule and its definition.");
                }

                $law = is_array($rule['law'] ?? null) ? $rule['law'] : [];
                $code = $tax . '.' . $name;

                if (! is_string($law['question'] ?? null)) {
                    throw new InvalidArgumentException("fiscal_parameters.taxes.{$tax}.rules.{$name}.law: a question is what the law is read for.");
                }

                $subject = $rule['subject'] ?? $entry['subject'] ?? null;
                // The rule's own part of the law where it names one, the tax's otherwise.
                $within = $law['within'] ?? $taxLaw['within'] ?? null;

                $definitions[$code] = new ParameterDefinition(
                    code: $code,
                    tax: $tax,
                    type: FiscalParameterValueType::from(is_string($rule['type'] ?? null) ? $rule['type'] : ''),
                    subject: ParameterSubject::from(is_string($subject) ? $subject : ParameterSubject::PERSON->value),
                    lawWithin: is_string($within) ? $within : null,
                    question: $law['question'],
                    answer: ParameterAnswer::from(is_string($law['answer'] ?? null) ? $law['answer'] : ParameterAnswer::WAGES->value),
                );
            }
        }

        $this->definitions = $definitions;
    }

    /** @return list<ParameterDefinition> in the order the configuration lists them */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function find(string $code): ?ParameterDefinition
    {
        return $this->definitions[$code] ?? null;
    }

    public function minimumWage(): ParameterDefinition
    {
        return $this->find($this->config->string('fiscal_parameters.minimum_wage.parameter'))
            ?? throw new InvalidArgumentException('fiscal_parameters.minimum_wage.parameter names no parameter.');
    }
}
