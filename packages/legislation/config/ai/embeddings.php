<?php

declare(strict_types=1);

// Hybrid legislation retrieval (`BLUEPRINT.md` §15.2) — the embedding half.
//
// The lexical half needs no configuration: it is a Postgres `tsvector` with the `romanian` text
// search configuration, decided in the migration. Only the dense half has a model behind it, and
// therefore choices. No default for any of them (ADR 0025 §4): the values are in .env.example, and
// one missing fails by name where it is read.
return [
    // The `python-api` service, over the internal `processing_net` network — its address and
    // token are `services.processing`, shared with document extraction and statement reading.
    // Self-hosted rather than an external embedding API: `docs/STATUS.md` blocked item #3
    // (provider, region, DPA) gates every external AI call, and the model running in our own
    // container sidesteps it for this corpus. The text embedded is public legislation; the
    // *queries* that reach it may not be.

    // Generous next to the document-extraction timeout: the very first call downloads ~2.3 GB
    // of weights and builds an ONNX session. Later calls are milliseconds.
    'timeout_seconds' => (int) env('RETRIEVAL_EMBEDDING_TIMEOUT_SECONDS'),

    // The switch between `UnconfiguredEmbeddingProvider` — search by words alone, nothing embedded —
    // and the real one: `true` or `false`, chosen when installing; anything else fails.
    'enabled' => env('RETRIEVAL_EMBEDDING_ENABLED'),

    // Must equal `ext_source_section_chunks.embedding`'s declared `vector(N)` *and* the Python
    // service's `EMBEDDING_DIMENSION`. All three are asserted against each other at runtime
    // rather than trusted — a mismatch writes vectors into the wrong space, which retrieves
    // plausible-looking nonsense instead of failing.
    'dimension' => (int) env('EMBEDDING_DIMENSION'),

    // There is deliberately no `model` key. The name of the model that produced a vector is
    // reported by the service that produced it and stored on the vector's own row; a name
    // configured on this side could only ever disagree with it, and once did —
    // `HttpEmbeddingProvider::modelIdentifier()` explains what that cost. `EMBEDDING_MODEL_ID`
    // still exists, in `compose.yaml`, where it tells the Python service which model to load.

    // Texts per HTTP call. The Python service batches internally as well; this bounds the
    // request body and lets a long backfill make visible progress instead of one giant call.
    'batch_size' => (int) env('RETRIEVAL_EMBEDDING_BATCH_SIZE'),
];
