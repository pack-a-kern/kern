<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Subjects;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\TaxRomania\Subjects\Actions\ChangeSubjectStatus;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * `PUT /api/v1/subjects/{subject}/status` — suspend, close, or reactivate a taxpayer.
 *
 * This is the endpoint a "delete" button reaches. There is no delete: see
 * `Kern\TaxRomania\Subjects\Actions\ChangeSubjectStatus` for why the schema forbids one.
 */
final class ChangeSubjectStatusController
{
    public function __invoke(Request $request, string $subject, ChangeSubjectStatus $change): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $model = TaxSubject::query()->findOrFail($subject);
        abort_unless(SubjectMembership::owns($user->id, $subject), 403);

        $data = $request->validate([
            'status' => ['required', Rule::enum(SubjectStatus::class)],
        ]);

        $status = $data['status'] instanceof SubjectStatus
            ? $data['status']
            : SubjectStatus::from((string) $data['status']);

        $change($model, $status, $user->id);

        return response()->json(['id' => $model->id, 'status' => $status->value]);
    }
}
