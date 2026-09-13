<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Subjects;

use App\Domain\Subjects\Actions\ChangeSubjectStatus;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Enums\SubjectStatus;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `PUT /api/v1/subjects/{subject}/status` — suspend, close, or reactivate a taxpayer.
 *
 * This is the endpoint a "delete" button reaches. There is no delete: see
 * `App\Domain\Subjects\Actions\ChangeSubjectStatus` for why the schema forbids one.
 */
final class ChangeSubjectStatusController extends Controller
{
    public function __invoke(Request $request, string $subject, ChangeSubjectStatus $change): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $model = TaxSubject::query()->findOrFail($subject);
        abort_unless($this->owns($user->id, $subject), 403);

        $data = $request->validate([
            'status' => ['required', Rule::enum(SubjectStatus::class)],
        ]);

        $status = $data['status'] instanceof SubjectStatus
            ? $data['status']
            : SubjectStatus::from((string) $data['status']);

        $change($model, $status, $user->id);

        return response()->json(['id' => $model->id, 'status' => $status->value]);
    }

    private function owns(string $userId, string $subjectId): bool
    {
        return SubjectMembership::query()
            ->where('user_id', $userId)
            ->where('subject_id', $subjectId)
            ->where('role', MembershipRole::OWNER->value)
            ->whereNull('revoked_at')
            ->exists();
    }
}
