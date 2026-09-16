<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Wallet\Actions\DeleteTag;
use App\Domain\Wallet\Models\Tag;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `DELETE .../wallet/tags/{tag}` — remove a label.
 *
 * Nothing but the label goes: the transactions keep their figures, their categories and their
 * evidence, and only stop being findable under this word.
 */
final class DeleteTagController extends Controller
{
    public function __invoke(Request $request, string $subject, string $tag, DeleteTag $delete): JsonResponse
    {
        $model = Tag::query()->findOrFail($tag);

        $delete($model, $this->actorId($request));

        return response()->json([], 204);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
