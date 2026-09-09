<?php

declare(strict_types=1);

// Catalogue of AI provider presets offered in Settings → AI. This is *reference data*, not
// configuration of a running service: choosing a preset only prefills `base_url` and offers a
// starting model list, and every field stays editable afterwards. The live values in effect are
// always the `settings` rows (see `config/settings.php`), never this file.
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

    // Sent with every Anthropic-dialect request. Anthropic requires this header and rejects the
    // request without it; pinning it here means an API revision is a config change, not a patch.
    'anthropic_version' => env('AI_ANTHROPIC_VERSION', '2023-06-01'),

    'timeout_seconds' => (int) env('AI_HTTP_TIMEOUT_SECONDS', 10),
];
