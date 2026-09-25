<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Services;

use Kern\TaxRomania\Declarations\Enums\DeclarationStatus;
use Kern\TaxRomania\Declarations\Exceptions\InvalidDeclarationTransitionException;
use Kern\TaxRomania\Declarations\Models\Declaration;

/**
 * The single sanctioned way any `Kern\TaxRomania\Declarations\Actions\*` class changes
 * `declarations.status` (`BLUEPRINT.md` §6.10). Enforces the exact transition matrix
 * `Kern\TaxRomania\Declarations\Enums\DeclarationStatus`'s own docblock names — every other transition
 * is `InvalidDeclarationTransitionException`, never a silent no-op or a best-effort guess.
 *
 * This is the *action-layer* guard; `Kern\TaxRomania\Declarations\Models\Declaration`'s own `saving`
 * hook is the independent *model-layer* guard against mutating an already-`SUBMITTED`+ row —
 * the two are deliberately separate (`docs/CODE-QUALITY.md` §1: they are not "the same check
 * twice", one governs the state machine's shape, the other governs raw persistence regardless of
 * which code path produced the write).
 */
final class DeclarationLifecycle
{
    /** @var array<string, list<string>> */
    private const array ALLOWED_TRANSITIONS = [
        'DRAFT' => ['VALIDATING'],
        'VALIDATING' => ['VALID', 'DRAFT'],
        'VALID' => ['SUBMITTED'],
        'SUBMITTED' => ['ACCEPTED', 'REJECTED'],
        'ACCEPTED' => [],
        'REJECTED' => [],
    ];

    /**
     * @param array<string, mixed> $attributes additional columns to persist alongside the status
     *                                          change (e.g. `snapshot_hash`/`snapshot_json` when
     *                                          moving to `VALID`, `locked_at` when moving to
     *                                          `SUBMITTED`)
     */
    public function transition(Declaration $declaration, DeclarationStatus $to, array $attributes = []): void
    {
        $from = $declaration->status;

        $allowed = self::ALLOWED_TRANSITIONS[$from->value];

        if (! in_array($to->value, $allowed, true)) {
            throw InvalidDeclarationTransitionException::forTransition($declaration->id, $from, $to);
        }

        $declaration->fill($attributes);
        $declaration->status = $to;
        $declaration->save();
    }
}
