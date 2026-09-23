<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters;

use App\Domain\TaxKnowledge\Enums\FiscalParameterValueType;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use InvalidArgumentException;

/**
 * The values looked for — `config/fiscal_parameters.php` — read once and checked, so the readers,
 * the search and the screen take typed definitions and never the raw configuration.
 */
final readonly class ParameterCatalog
{
    /** @var array<string, ParameterDefinition> */
    private array $definitions;

    public function __construct(private ConfigRepository $config)
    {
        $definitions = [];

        foreach ($config->array('fiscal_parameters.parameters') as $code => $entry) {
            if (! is_string($code) || ! is_array($entry)) {
                throw new InvalidArgumentException('fiscal_parameters.parameters: every entry is a code and its definition.');
            }

            $law = is_array($entry['law'] ?? null) ? $entry['law'] : [];

            if ($law !== [] && ! is_string($law['question'] ?? null)) {
                throw new InvalidArgumentException("fiscal_parameters.parameters.{$code}.law: a question is what the law is read for.");
            }

            $definitions[$code] = new ParameterDefinition(
                code: $code,
                type: FiscalParameterValueType::from(is_string($entry['type'] ?? null) ? $entry['type'] : ''),
                subject: ParameterSubject::from(is_string($entry['subject'] ?? null) ? $entry['subject'] : ParameterSubject::PERSON->value),
                lawWithin: is_string($law['within'] ?? null) ? $law['within'] : null,
                question: is_string($law['question'] ?? null) ? $law['question'] : null,
                answer: ParameterAnswer::from(is_string($law['answer'] ?? null) ? $law['answer'] : ParameterAnswer::WAGES->value),
            );
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
