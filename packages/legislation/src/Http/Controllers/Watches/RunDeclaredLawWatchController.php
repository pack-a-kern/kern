<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers\Watches;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Legislation\Watches\MirrorDeclaredWatch;
use Kern\Legislation\Watches\StartWatchRun;
use Kern\Legislation\Watches\WatchDeclaration;
use Kern\Legislation\Watches\WatchSettings;

/**
 * `POST /api/v1/legislation/watches/declared/{key}/run` — run a package-declared watch now, by the
 * key it was declared under, mirroring its row first if nothing has mirrored it yet.
 *
 * This is how the setup screen imports a programme's acts on a fresh install (ADR 0027): the
 * minimum-wage decision, a bond order. On a fresh install `ext_law_watches` is empty — nothing has
 * run the hourly watch task to mirror the declarations — so {@see RunLawWatchController}, which
 * takes a row's id, has no row to name. This takes the declared *key* instead, materialises exactly
 * the row the hourly run would ({@see MirrorDeclaredWatch}), and claims the run through the same
 * {@see StartWatchRun} as "Run now".
 *
 * It takes no field from the request but the key, which must match a watch already declared in
 * configuration (404 otherwise) — the search terms come from the declaring package's environment,
 * never from the caller. So it needs no `verify-recent`: it does nothing an operator could not
 * already trigger, and cannot bring a watch of the caller's own choosing into being. `operations.run`
 * and the operator throttle (the group) are the whole of its authority.
 */
final class RunDeclaredLawWatchController
{
    public function __invoke(
        Request $request,
        string $key,
        ConfigRepository $config,
        MirrorDeclaredWatch $mirror,
        StartWatchRun $start,
    ): JsonResponse {
        $declaration = null;

        foreach (WatchSettings::read($config)->declared as $candidate) {
            if ($candidate->key === $key) {
                $declaration = $candidate;

                break;
            }
        }

        abort_if(! $declaration instanceof WatchDeclaration, 404);

        $watch = $mirror($declaration);

        $actor = $request->user()?->getAuthIdentifier();
        $actor = is_scalar($actor) ? (string) $actor : '';

        if (! $watch->enabled) {
            return response()->json(['reason' => 'WATCH_PAUSED', 'message' => 'A paused watch is not run.'], 409);
        }

        $start($watch, $actor);

        return response()->json(LawWatchView::of($watch->refresh(), $actor), 202);
    }
}
