<?php

declare(strict_types=1);

// System-wide runtime settings (docs/handoff/041-settings-module.md). Distinct from every other
// file in this directory: the values it describes can *also* be changed at runtime through
// `PUT /api/v1/settings/ai` (stored, encrypted when sensitive, in the `settings` table) without a
// container restart — env vars here are consulted only as a fallback, never as the live value
// once an administrator has saved one through the API.
return [

    'ai' => [
        // Read by App\Domain\Settings\Services\ResolveAiProviderCredentials only for a field
        // whose `settings` row does not exist yet — see the file-level docblock above. Named
        // identically to apps/processing's own AI_* variables (docs/handoff/019-provider-
        // agnostic-ai equivalent, `apps/processing/src/processing/ai/config.py`) purely for
        // operator familiarity: they are two independent environments (this Laravel container's
        // vs. python-api's own), never a shared value read by both processes.
        'env_fallback' => [
            'enabled' => filter_var(env('AI_ENABLED', false), FILTER_VALIDATE_BOOL),
            'provider' => env('AI_PROVIDER'),
            'base_url' => env('AI_BASE_URL'),
            'model' => env('AI_MODEL'),
            'api_key' => env('AI_API_KEY'),
        ],
    ],

];
