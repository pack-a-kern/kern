<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Enums;

/**
 * `BLUEPRINT.md` §2.2/§5.2 — all five subject types exist in the schema so PFA/II/IF/SRL can be
 * added later without a migration rewrite, but **v1 implements application logic for `PF` only**.
 * No other module may branch on `PFA`/`II`/`IF`/`SRL` yet — that is exactly the "cod mort pentru
 * funcții viitoare" §2.2 forbids. `Kern\TaxRomania\Subjects\Actions\RegisterPfSubject` is the only
 * place this enum's `PF` case is ever constructed by this task's code.
 */
enum SubjectType: string
{
    case PF = 'PF';
    case PFA = 'PFA';
    case II = 'II';
    case IF = 'IF';
    case SRL = 'SRL';
}
