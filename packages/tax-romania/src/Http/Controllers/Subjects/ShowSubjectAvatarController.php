<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Subjects;

use Illuminate\Http\Request;
use Kern\Platform\Identity\Avatars\AvatarResponse;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * `GET /api/v1/subjects/{subject}/avatar` — a taxpayer's picture, to the people who work on it.
 *
 * The bytes and the headers come from {@see AvatarResponse}, the same ones a user's picture gets. The
 * only thing this controller decides is who may look, and it is the question that matters: a taxpayer
 * is tenant data, so membership is the rule — anyone whose membership is live, at any role, because
 * everyone who can see the taxpayer's figures can already see its name.
 *
 * Not a public disk and a symlink. The picture would then be readable by anybody who ever learned the
 * URL, including somebody whose membership was revoked this morning, and the id in that URL would
 * confirm the taxpayer exists to anybody who guessed it.
 */
final class ShowSubjectAvatarController
{
    public function __invoke(Request $request, TaxSubject $subject, AvatarResponse $avatar): SymfonyResponse
    {
        $viewer = $request->user();

        abort_if($viewer === null, 403);

        $viewerKey = $viewer->getKey();

        abort_unless(
            is_scalar($viewerKey) && $this->belongsTo((string) $viewerKey, $subject->encryptionSubjectId()),
            403,
        );

        return $avatar->of($subject);
    }

    private function belongsTo(string $userId, string $subjectId): bool
    {
        return SubjectMembership::query()
            ->where('user_id', $userId)
            ->where('subject_id', $subjectId)
            ->whereNull('revoked_at')
            ->exists();
    }
}
