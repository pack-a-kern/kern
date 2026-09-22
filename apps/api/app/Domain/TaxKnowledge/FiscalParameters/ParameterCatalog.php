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
            $patterns = [];

            foreach (is_array($entry['form'] ?? null) ? $entry['form'] : [] as $form) {
                if (! is_array($form) || ! is_string($form['pattern'] ?? null) || ! is_array($form['years'] ?? null) || $form['years'] === []) {
                    throw new InvalidArgumentException("fiscal_parameters.parameters.{$code}.form: every formula has a pattern and the years it is about.");
                }

                $patterns[] = ['pattern' => $form['pattern'], 'years' => array_values(array_map(static fn (mixed $year): int => is_numeric($year) ? (int) $year : 0, $form['years']))];
            }

            $definitions[$code] = new ParameterDefinition(
                code: $code,
                type: FiscalParameterValueType::from(is_string($entry['type'] ?? null) ? $entry['type'] : ''),
                lawWithin: is_string($law['within'] ?? null) ? $law['within'] : null,
                lawSays: is_string($law['says'] ?? null) ? $law['says'] : null,
                formPatterns: $patterns,
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
