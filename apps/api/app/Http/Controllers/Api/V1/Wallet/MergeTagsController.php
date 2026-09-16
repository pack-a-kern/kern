<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Wallet\Actions\MergeTags;
use App\Domain\Wallet\Models\Tag;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST .../wallet/tags/{tag}/merge` — fold this tag into another one, which survives.
 *
 * The tag in the path is the one that goes, so the URL says what is being acted on, and the body
 * names where its transactions and rules end up.
 */
final class MergeTagsController extends Controller
{
    public function __invoke(Request $request, string $subject, string $tag, MergeTags $merge): JsonResponse
    {
        $validated = $request->validate([
            'into_tag_id' => ['required', 'uuid'],
        ]);

        // Both tenant-scoped: a tag belonging to another subject is not found rather than merged.
        $from = Tag::query()->findOrFail($tag);
        $into = Tag::query()->findOrFail((string) $validated['into_tag_id']);

        $merge($from, $into, $this->actorId($request));

        return response()->json(['id' => $into->id]);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
