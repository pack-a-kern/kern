<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Subjects;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Kern\Platform\Identity\Avatars\RemoveAvatar;
use Kern\Platform\Identity\Avatars\SetAvatar;
use Kern\Platform\Uploads\Exceptions\UploadRejectedException;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * `POST /api/v1/subjects/{subject}/avatar` — gives a taxpayer a picture.
 * `DELETE /api/v1/subjects/{subject}/avatar` — takes it away again.
 *
 * Owner only, the same rule as changing what a taxpayer is or whether it is in use
 * ({@see ChangeSubjectStatusController}): a picture is how everyone on the account recognises which
 * taxpayer they are working in, so changing it is not something a member should be able to do to
 * everybody else's screen.
 *
 * Everything that makes an upload safe — weighed before it is decoded, scanned as it arrived, rebuilt
 * so the stored file shares no bytes with it — is {@see SetAvatar}'s, unchanged from the user case.
 * The validation here is the cheap half, and deliberately not Laravel's `image` rule, which trusts the
 * MIME type and the extension that the uploader supplied.
 */
final class UpdateSubjectAvatarController
{
    public function store(
        Request $request,
        TaxSubject $subject,
        SetAvatar $set,
        ConfigRepository $config,
    ): JsonResponse {
        $actorId = $this->ownerId($request, $subject);

        $maxKilobytes = intdiv($config->integer('avatars.max_bytes'), 1024);

        $request->validate([
            'avatar' => ['required', 'file', "max:{$maxKilobytes}"],
        ]);

        $file = $request->file('avatar');

        if (! $file instanceof UploadedFile) {
            abort(422, 'No file was uploaded.');
        }

        $bytes = file_get_contents($file->getRealPath());

        if ($bytes === false) {
            abort(422, 'The upload could not be read.');
        }

        try {
            $set($subject, $bytes, $actorId);
        } catch (UploadRejectedException $e) {
            // 422 for every refusal, scanner outages included — see the user controller's note: a 503
            // reads as "try again shortly", and a scanner that is down is not something the person
            // uploading can wait out.
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json([
            'avatar_updated_at' => $subject->avatarUpdatedAt(),
        ]);
    }

    public function destroy(Request $request, TaxSubject $subject, RemoveAvatar $remove): JsonResponse
    {
        $actorId = $this->ownerId($request, $subject);

        $remove($subject, $actorId);

        return response()->json(['avatar_updated_at' => null]);
    }

    /** The caller's id, having established that they own this taxpayer. */
    private function ownerId(Request $request, TaxSubject $subject): string
    {
        $viewer = $request->user();

        abort_if($viewer === null, 403);

        $key = $viewer->getKey();

        abort_unless(is_scalar($key), 403);

        $userId = (string) $key;

        $owns = SubjectMembership::query()
            ->where('user_id', $userId)
            ->where('subject_id', $subject->encryptionSubjectId())
            ->where('role', MembershipRole::OWNER->value)
            ->whereNull('revoked_at')
            ->exists();

        abort_unless($owns, 403);

        return $userId;
    }
}
