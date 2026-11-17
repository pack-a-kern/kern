<?php

declare(strict_types=1);

/*
 * Reading a Romanian identity card (carte de identitate) into a new taxpayer — ADR 0028.
 *
 * The provider, credentials and dialect are shared with every other use of a model (ADR 0021,
 * `ai.providers`); only what is specific to this one read lives here. The prompts address a model,
 * not a person: they are not user-facing copy and are deliberately not translated. They are config,
 * not PHP, so a phrase can be tuned for a particular model without a deploy.
 */

return [
    /*
     * Which model reads the card, and how long it may take.
     *
     * `model` is an override: null (unset) falls back to the provider's configured `ai.model`, the
     * same rule the statement reader uses. A card is a vision job, so this must resolve to a
     * vision-capable model; a model that cannot see an image returns no usable JSON and the read
     * reports "could not read", which the form treats as "type it by hand".
     */
    'read' => [
        'model' => env('ID_CARD_AI_MODEL'),
        'max_tokens' => (int) env('ID_CARD_AI_MAX_TOKENS', 4_000),
        'timeout_seconds' => (int) env('ID_CARD_AI_TIMEOUT_SECONDS', 120),

        // The lane the read runs on. The document-AI queue by default: a read is one vision call and
        // belongs with the other model work, not on the default queue in front of quick jobs.
        'queue' => env('ID_CARD_AI_QUEUE', 'documents-ai'),

        /*
         * The card is held only as encrypted ciphertext in the cache, keyed by an unguessable
         * token, between the upload and the worker reading it, and the result between the worker
         * and the browser fetching it. Short, because both hops are a few seconds apart; single-use
         * (deleted on read) regardless of this ceiling.
         */
        'scratch_ttl_seconds' => (int) env('ID_CARD_SCRATCH_TTL_SECONDS', 180),

        /*
         * Hard ceiling on the uploaded image, checked before anything parses it. A phone photo of a
         * card is well under this; a larger upload is refused rather than sent.
         */
        'max_image_bytes' => (int) env('ID_CARD_MAX_IMAGE_BYTES', 10_485_760), // 10 MiB

        'system' => env('ID_CARD_PROMPT_SYSTEM', implode(' ', [
            'You read a single Romanian identity card (carte de identitate, CI) from an image and',
            'return only what is printed on it. You never guess, infer, or complete a value that is',
            'not clearly legible: a field you cannot read confidently is null, not a best effort.',
            'You return strictly the requested JSON and nothing else.',
        ])),

        'instruction' => env('ID_CARD_PROMPT_INSTRUCTION', implode(' ', [
            'Read this Romanian identity card and return JSON with these fields, each null if not',
            'clearly legible: cnp (the 13-digit personal numeric code, CNP); last_name (nume);',
            'first_name (prenume); address (domiciliu, as printed on one line); series (seria);',
            'number (numărul); valid_until (data expirării, as YYYY-MM-DD).',
            'Also return face_box: the rectangle around the cardholder\'s photograph, as fractions of',
            'the image in [0,1] with keys x, y, w, h (x,y the top-left corner), or null if no',
            'photograph is visible.',
        ])),
    ],
];
