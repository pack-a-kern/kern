<?php

declare(strict_types=1);

namespace Kern\Documents\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Documents\Consent\DocumentAiConsentGate;
use Kern\Platform\Tenancy\Contracts\TenantGate;

/**
 * `GET /api/v1/documents/ai-consents` — the tax subjects that currently permit sending scanned
 * documents to an external AI provider (ADR 0005).
 *
 * Returns the *set* of consenting subject ids rather than a value per subject, so a screen showing
 * a switch for each of a person's subjects needs one request instead of one per row. Consent is
 * still granted and revoked one subject at a time, through the endpoints beside this.
 *
 * Scoped to the taxpayers the caller may open, as the platform's tenant gate answers it: consent is
 * a property of a taxpayer, and who consented is not something one subject's owner may learn about
 * another's.
 *
 * Reads through `DocumentAiConsentGate` rather than the model: that is where ADR 0005's rule for
 * what counts as consent lives, and the model's docblock makes going around it a defect.
 */
final class ShowDocumentAiConsentsController
{
    public function __invoke(Request $request, DocumentAiConsentGate $consent, TenantGate $tenants): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null, 401);

        $key = $user->getKey();

        abort_unless(is_scalar($key), 401);

        return response()->json(['consented_subject_ids' => $consent->consentedAmong($tenants->tenantsFor((string) $key))]);
    }
}
