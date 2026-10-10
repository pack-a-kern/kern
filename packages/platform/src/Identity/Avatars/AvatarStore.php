<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Avatars;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Where an avatar's bytes sit, and what they are called.
 *
 * One file per user, overwritten in place. Keeping a new file per upload would mean every face a person
 * ever chose stays on disk until something sweeps them, and a sweep that has to decide which of several
 * files is current is a sweep that will eventually delete the wrong one. The name is derived from the
 * user's id, so there is nothing to look up and nothing to drift.
 *
 * The id is a UUID from the database, never anything a request supplies, so it cannot contain a path
 * separator — but the name is still built rather than concatenated, and `basename` makes that explicit
 * for whoever reads this next.
 */
final readonly class AvatarStore
{
    public function __construct(private Filesystem $disk)
    {
    }

    public function put(string $userId, string $bytes): string
    {
        $path = $this->pathFor($userId);
        $this->disk->put($path, $bytes);

        return $path;
    }

    public function read(string $path): ?string
    {
        $contents = $this->disk->get($path);

        return is_string($contents) ? $contents : null;
    }

    public function delete(string $path): void
    {
        $this->disk->delete($path);
    }

    private function pathFor(string $userId): string
    {
        return basename($userId) . '.webp';
    }
}
