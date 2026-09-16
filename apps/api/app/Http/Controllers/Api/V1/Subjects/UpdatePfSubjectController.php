<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Subjects;

use App\Domain\Subjects\Actions\UpdatePfSubject;
use App\Domain\Subjects\DataTransferObjects\UpdatePfSubjectInput;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/v1/subjects/{subject}` — corrects a taxpayer's details.
 *
 * Owner only. A membership that merely grants access to someone else's books is not permission to
 * rewrite whose books they are, and the check is here rather than in the action because "who may"
 * is a question about the request, not about the correction.
 */
final class UpdatePfSubjectController extends Controller
{
    public function __invoke(Request $request, string $subject, UpdatePfSubject $update): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $model = TaxSubject::query()->findOrFail($subject);
        abort_unless($this->owns($user->id, $subject), 403);

        $data = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:255'],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'county_code' => ['sometimes', 'nullable', 'string', Rule::in(array_keys((array) config('reference.counties.ro')))],
            'locality' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $update($model, new UpdatePfSubjectInput(
            displayName: $data['display_name'] ?? null,
            firstName: $data['first_name'] ?? null,
            lastName: $data['last_name'] ?? null,
            countyCode: $data['county_code'] ?? null,
            locality: $data['locality'] ?? null,
            address: $data['address'] ?? null,
        ), $user->id);

        return response()->json(['id' => $model->id]);
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
