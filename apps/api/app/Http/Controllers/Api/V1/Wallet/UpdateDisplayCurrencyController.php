<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Subjects\Models\TaxSubject;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Tenancy\TenantContext;

/**
 * `PUT /api/v1/subjects/{subject}/wallet/display-currency` — which currency this subject's totals
 * are shown in.
 *
 * A display preference and nothing more: it changes how a dashboard adds figures up, never how a
 * tax figure is computed (`BLUEPRINT.md` §7.4 leaves that to each rule, with its own provenance).
 *
 * `TaxSubject` is the tenancy root, so it carries no `TenantScope` of its own and no query
 * against it can be "scoped" the way every business table is. The guard is therefore the bound
 * `TenantContext`: the id written to comes from `currentTenantId()`, which is what
 * `authorizeAndActivate()` produced after checking the membership — not the `{subject}` path
 * segment, which is merely the string the middleware was asked to check.
 *
 * The two are equal whenever the middleware ran. Reading the authorised value rather than the raw
 * one means that stays true even if this route is ever moved out from behind that middleware: it
 * would then fail loudly with `NoTenantContextException` instead of quietly writing to whatever id
 * the URL carried.
 *
 * Any ISO 4217 code is accepted rather than an allowlist. A currency with no published BNR rate
 * simply produces a total the screen labels as partial, which is a better failure than refusing a
 * currency someone genuinely holds.
 */
final class UpdateDisplayCurrencyController extends Controller
{
    public function __invoke(Request $request, string $subject, TenantContext $tenant): JsonResponse
    {
        $validated = $request->validate([
            // Uppercase enforced rather than normalised: silently rewriting input is how "eur" and
            // "EUR" become two different stored preferences.
            'display_currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ]);

        $model = TaxSubject::query()->findOrFail($tenant->currentTenantId());
        $model->display_currency = (string) $validated['display_currency'];
        $model->save();

        return response()->json(['display_currency' => $model->display_currency]);
    }
}
