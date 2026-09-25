<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\DataTransferObjects\OpenFinancialAccountInput;
use App\Domain\Wallet\Enums\AccountKind;
use App\Domain\Wallet\Models\FinancialAccount;
use Kern\Platform\Tenancy\TenantScope;

/**
 * Gives a taxpayer the one source they should never have to create.
 *
 * Recording income used to require opening an account first — a setup step that asked a person to
 * model their banking before they could answer a single question about their money. The general
 * source removes it: it exists from the moment the taxpayer does, and everything typed by hand, every
 * receipt, every rental payment and every bank statement lands there unless told otherwise.
 *
 * Idempotent by design. It is called after registration and again wherever a subject that predates
 * this behaviour might be read, so "has one already" is the normal case rather than an error.
 */
final readonly class EnsureGeneralSource
{
    public function __construct(private OpenFinancialAccount $open)
    {
    }

    public function __invoke(string $taxSubjectId, ?string $actorId = null): FinancialAccount
    {
        // Explicitly outside the tenant scope: this runs immediately after registration, before
        // anything has activated a `TenantContext` for a subject that did not exist a moment ago.
        // The subject id is supplied by the caller that just created it, so the scope would only
        // re-apply a filter that is already there.
        $existing = FinancialAccount::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tax_subject_id', $taxSubjectId)
            ->where('kind', AccountKind::GENERAL->value)
            ->first();

        if ($existing instanceof FinancialAccount) {
            return $existing;
        }

        return ($this->open)(new OpenFinancialAccountInput(
            taxSubjectId: $taxSubjectId,
            kind: AccountKind::GENERAL,
            // No name: the screen renders a translated label for this kind, because "General" is
            // the system's own name for it rather than one a person chose.
            displayName: null,
            // No currency: the general source holds whatever arrives, and every amount carries its
            // own. A default here would only pre-fill a form field with a guess.
            currencyCode: null,
            actorId: $actorId,
        ));
    }
}
