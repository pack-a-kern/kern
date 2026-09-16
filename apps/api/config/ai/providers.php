<?php

declare(strict_types=1);

/*
 * Every model this application talks to, and how it talks to them.
 *
 * `config/ai/` holds one file per use of a model — `documents.php` reads documents, `statements.php`
 * reads bank statements, `embeddings.php` indexes legislation — and this file, which is what they
 * share: the providers, the credentials fallback, the connection limits and the queue model calls
 * run on. Tuning a model used to mean knowing five files; it now means this folder.
 */

// Catalogue of AI provider presets offered in Settings → AI. This is *reference data*, not
// configuration of a running service: choosing a preset only prefills `base_url` and offers a
// starting model list, and every field stays editable afterwards. The live values in effect are
// always the `settings` rows — `env_fallback` below until one is saved — never this file.
//
// It lives in config rather than in a PHP enum or a TypeScript constant so an operator can add a
// provider — a new self-hosted gateway, a regional endpoint — by editing one file, with no code
// change and no frontend rebuild (BLUEPRINT.md §19.1: nothing hardcoded).
//
// `dialect` is the only field with behavioural weight. It selects the HTTP wire format:
//   - `openai`    POST {base_url}/v1/chat/completions, Authorization: Bearer <key>
//   - `anthropic` POST {base_url}/v1/messages, x-api-key: <key>, anthropic-version header
// Anthropic's public API is *not* OpenAI-compatible, so listing it as a preset without this
// distinction would produce a preset that can never connect. See docs/adr/0007.
//
// `models_path` / `models_shape` drive the "load models from provider" button. `models_shape`:
//   - `data_id`     {"data": [{"id": "..."}]}            OpenAI, Anthropic, OpenRouter, most gateways
//   - `models_name` {"models": [{"name": "..."}]}        Ollama's native /api/tags
// `models_path: null` means the provider publishes no list endpoint; only `models` is offered.
//
// `models` is a fallback list used before a live fetch succeeds (and for providers with no list
// endpoint). It is intentionally allowed to go stale — the live fetch is the source of truth, and
// a model name that no longer exists surfaces as a provider error on the next connection test.
return [
    'presets' => [
        'openai' => [
            'label' => 'OpenAI',
            'dialect' => 'openai',
            'base_url' => 'https://api.openai.com',
            'requires_api_key' => true,
            'models_path' => '/v1/models',
            'models_shape' => 'data_id',
            'models' => ['gpt-4o', 'gpt-4o-mini'],
            'docs_url' => 'https://platform.openai.com/docs/api-reference',
        ],

        'anthropic' => [
            'label' => 'Anthropic',
            'dialect' => 'anthropic',
            'base_url' => 'https://api.anthropic.com',
            'requires_api_key' => true,
            'models_path' => '/v1/models',
            'models_shape' => 'data_id',
            'models' => [],
            'docs_url' => 'https://docs.anthropic.com/en/api',
        ],

        'ollama' => [
            // Self-hosted Llama/Mistral/Qwen etc. Ollama serves its own API at /api/* and an
            // OpenAI-compatible surface at /v1/*; we speak the latter and list from the former,
            // because /api/tags returns what is actually pulled onto this machine.
            'label' => 'Ollama (self-hosted)',
            'dialect' => 'openai',
            'base_url' => 'http://host.docker.internal:11434',
            'requires_api_key' => false,
            'models_path' => '/api/tags',
            'models_shape' => 'models_name',
            'models' => [],
            'docs_url' => 'https://github.com/ollama/ollama/blob/main/docs/api.md',
        ],

        'openrouter' => [
            'label' => 'OpenRouter',
            'dialect' => 'openai',
            'base_url' => 'https://openrouter.ai/api',
            'requires_api_key' => true,
            'models_path' => '/v1/models',
            'models_shape' => 'data_id',
            'models' => [],
            'docs_url' => 'https://openrouter.ai/docs',
        ],

        'custom' => [
            // Any other OpenAI-compatible endpoint: vLLM, LM Studio, llama.cpp's server, Groq,
            // Together, a corporate gateway. Nothing is prefilled — the operator supplies the URL.
            'label' => 'Other (OpenAI-compatible)',
            'dialect' => 'openai',
            'base_url' => null,
            'requires_api_key' => false,
            'models_path' => '/v1/models',
            'models_shape' => 'data_id',
            'models' => [],
            'docs_url' => null,
        ],
    ],


    /*
     * The provider, address, model and key used until an administrator saves them in Settings → AI.
     *
     * Read by App\Domain\Settings\Services\ResolveAiProviderCredentials only for a field whose
     * `settings` row does not exist yet: once saved through the API (stored in the `settings` table,
     * encrypted when sensitive), the database value wins without a restart. Named like
     * apps/processing's own AI_* variables for operator familiarity only — the two containers'
     * environments are independent, never a shared value.
     */
    'env_fallback' => [
        'enabled' => filter_var(env('AI_ENABLED', false), FILTER_VALIDATE_BOOL),
        'provider' => env('AI_PROVIDER'),
        'base_url' => env('AI_BASE_URL'),
        'model' => env('AI_MODEL'),
        'api_key' => env('AI_API_KEY'),
    ],

    // Whether the endpoint implements the `docscanner` extension, which cleans up a photographed
    // document before it is read. A capability of the gateway, not a preference — OpenAI has no
    // equivalent, so the upload screen only offers it when this says the provider can.
    'supports_enhancement' => (bool) env('AI_SUPPORTS_ENHANCEMENT', false),

    // Sent with every Anthropic-dialect request. Anthropic requires this header and rejects the
    // request without it; pinning it here means an API revision is a config change, not a patch.
    'anthropic_version' => env('AI_ANTHROPIC_VERSION', '2023-06-01'),

    // Bounds the connection test's ping: one token, answered in milliseconds. A provider slower
    // than this is not usable interactively, which is what the test is deciding. Each use of a model
    // has its own, much longer, timeout for real work.
    'timeout_seconds' => (int) env('AI_HTTP_TIMEOUT_SECONDS', 10),

    /*
     * Time a queued job needs around the model call itself: reading and decrypting the input,
     * writing the answer. Added to the call's own timeout to size the job, so it is one value
     * rather than a copy in each job that makes a call.
     */
    'job_timeout_margin_seconds' => (int) env('AI_JOB_TIMEOUT_MARGIN_SECONDS', 60),

    /*
     * The queue every job that calls a model runs on — documents, statements.
     *
     * One process works it (config/horizon.php), because the constraint is one model endpoint rather
     * than this machine's cores: several calls at once to a self-hosted model finish no sooner, and
     * each creeps toward its timeout for no reason but company. On `default` these jobs were killed
     * at 60 seconds. HORIZON_AI_QUEUE_TIMEOUT_SECONDS must stay above every job's own timeout.
     */
    'queue' => env('AI_QUEUE', 'documents-ai'),
];
