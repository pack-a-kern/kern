<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Exceptions;

use RuntimeException;

/**
 * Nothing is registered to assess with.
 *
 * `TaxContext` refuses an empty rule list already, saying "missing required field ruleCodes" — which is true
 * and tells nobody what to do about it. A fresh installation is in exactly this state: the rule classes exist
 * in code and no `tax_rule_versions` row points at them, so the engine cannot find them.
 */
final class NoTaxRulesRegisteredException extends RuntimeException
{
    public static function create(): self
    {
        return new self('No tax rules are registered. Run `kern:tax:register-rules` to record the rule versions first.');
    }
}
