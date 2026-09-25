<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\Wallet\Actions\DeleteTag;
use Kern\TaxRomania\Wallet\Models\Tag;

/**
 * `DELETE .../wallet/tags/{tag}` — remove a label.
 *
 * Nothing but the label goes: the transactions keep their figures, their categories and their
 * evidence, and only stop being findable under this word.
 */
final class DeleteTagController
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
