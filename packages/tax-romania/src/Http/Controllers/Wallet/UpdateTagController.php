<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\Wallet\Actions\RenameTag;
use Kern\TaxRomania\Wallet\Models\Tag;
use Kern\TaxRomania\Wallet\Services\TagDirectory;

/**
 * `PATCH .../wallet/tags/{tag}` — rename a tag.
 *
 * A name the taxpayer already uses is refused with `TAG_EXISTS` rather than merged: merging deletes
 * a tag and rewrites what every transaction carrying it says, and the screen has a separate control
 * that says so.
 */
final class UpdateTagController
{
    public function __invoke(Request $request, string $subject, string $tag, RenameTag $rename): JsonResponse
    {
        // Tenant-scoped: another subject's tag is not found rather than renamed.
        $model = Tag::query()->findOrFail($tag);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:' . TagDirectory::MAX_LENGTH],
        ]);

        if (! $rename($model, (string) $validated['name'], $this->actorId($request))) {
            return response()->json([
                'message' => 'A tag with this name already exists.',
                'reason' => 'TAG_EXISTS',
            ], 409);
        }

        return response()->json(['id' => $model->id, 'name' => (string) $model->name_ciphertext]);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
