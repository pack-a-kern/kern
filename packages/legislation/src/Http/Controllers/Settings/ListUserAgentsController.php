<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Kern\Legislation\Queries\UserAgentChoices;

/** `GET /api/v1/settings/browser/user-agents` — the names the Browser card offers, by engine and system. */
final class ListUserAgentsController
{
    public function __invoke(UserAgentChoices $choices): JsonResponse
    {
        return response()->json($choices());
    }
}
