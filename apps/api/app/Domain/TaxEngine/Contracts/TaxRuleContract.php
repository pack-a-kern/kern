<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Contracts;

use App\Domain\TaxEngine\DataTransferObjects\TaxRuleInput;
use App\Domain\TaxEngine\DataTransferObjects\TaxRuleOutcome;

/**
 * `BLUEPRINT.md` §11.2/ADR 0004 — the one contract every fiscal rule implements. A conforming
 * implementation is as close to a pure function as PHP allows:
 *
 * - no HTTP call;
 * - no Eloquent query, no `DB::` facade use;
 * - no `now()`/`Carbon::now()`/any wall-clock read — `$input->asOf` is the only date;
 * - no `env()`/`getenv()` read;
 * - no randomness (`rand()`, `mt_rand()`, `random_int()`, `random_bytes()`, …);
 * - no cache read/write.
 *
 * `App\Domain\TaxEngine\Services\RuleResolver`/`ParameterResolver` (which query the database via
 * the `TaxRuleVersionProvider`/`FiscalParameterProvider` ports — see
 * `docs/adr/0010-tax-engine-ports-and-adapters.md`) run *before* `evaluate()` is called,
 * assembling `$input` — never inside it. `tests/Unit/Domain/TaxEngine/RulePuritySourceScannerTest.php` and
 * `tests/Feature/Domain/TaxEngine/RulePurityArchitectureTest.php` enforce this with a source scan
 * of every class implementing this interface under `app/Domain/TaxEngine`, not by convention
 * alone (`docs/AGENTS.md` §28 rule 4 — "modify a tax rule without a test").
 *
 * Implementations must have a public, zero-argument constructor — `App\Domain\TaxEngine\Services\
 * RuleInvoker` instantiates them directly (`new $class()`), never through the container, so a
 * rule cannot acquire a dependency the container might otherwise inject.
 */
interface TaxRuleContract
{
    public function evaluate(TaxRuleInput $input): TaxRuleOutcome;
}
