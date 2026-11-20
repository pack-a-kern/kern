<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Subjects;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\Subjects\Actions\ResetTaxSubject;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * `POST /api/v1/subjects/{subject}/reset` — empty a taxpayer back to nothing.
 *
 * Not the status change, which only *closes* a taxpayer: this strips every account, deposit,
 * holding, document, import, rental and computed figure the subject owns and leaves an anonymous,
 * re-addable husk ({@see ResetTaxSubject}). Owner-only — a non-owner member may read a taxpayer but
 * not erase it. Inside the `tax-subject` group so the tenant is bound before the action's scoped
 * queries run; the ownership check is the authorization on top of that binding.
 */
final class ResetTaxSubjectController
{
    public function __invoke(Request $request, string $subject, ResetTaxSubject $reset): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $model = TaxSubject::query()->findOrFail($subject);
        abort_unless(SubjectMembership::owns($user->id, $subject), 403);

        $reset($model, $user->id);

        return response()->json(['id' => $model->id, 'reset' => true]);
    }
}
