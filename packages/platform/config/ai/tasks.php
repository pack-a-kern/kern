<?php

declare(strict_types=1);

/*
 * Every job this installation puts to a language or vision model, and where each one reads the
 * model name it will use.
 *
 * The settings screen lists these so an operator can see, in one place, which model answers each
 * kind of work and whether a model actually exists for it — a question that was previously spread
 * across `config/ai/statements.php`, `config/ai/idcard.php` and `config/ai/legislation.php`, one
 * file per module, with no screen that gathered them.
 *
 * ## Why the keys live here and not in each module
 *
 * This is the platform, and the platform must not depend on the modules — so it cannot import their
 * code to ask them. What it can do is name a `SettingKey` of its own, which is editable on the
 * settings screen and whose {@see \Kern\Platform\Settings\Enums\SettingKey::configPath()} bridges to
 * the module's config key (a plain string merged into one `config('ai.*')` tree at boot). Naming a
 * string is not a code dependency: Deptrac sees nothing cross-module here, and a module that renames
 * its key updates the string in one `configPath()` arm. That is the seam the settings screen reads
 * and writes through.
 *
 * ## `setting_key`
 *
 * The `SettingKey` value holding this task's own model — an admin override, falling back to the env
 * default behind it ({@see \Kern\Platform\Settings\Services\ResolveTaskModel}) — or `null` for a task
 * that has no model of its own and always uses the provider's configured `ai.model` (the base
 * model). A non-null key that resolves to nothing also falls back to the base model — unless
 * `optional` is true, which marks a task that is simply off when unset (a second reader nobody
 * configured), and so has no model rather than the base one.
 *
 * The embedding model is deliberately absent: it runs in this installation's own process from
 * `EMBEDDING_MODEL_ID`, is never asked of an external provider, and so is neither listed here nor
 * checked against a provider's catalogue (`config/ai/embeddings.php`).
 */
return [

    // Reading an uploaded document (a receipt, an invoice) with a vision model. The base model
    // itself: the reader has no override, so this row is where `ai.model` is seen in use.
    'document_read' => [
        'setting_key' => null,
        'optional' => false,
    ],

    // Deciding how a bank statement's columns are built — a reasoning job, often a different model
    // from the document reader (ADR 0021).
    'statement_mapping' => [
        'setting_key' => 'ai.statement_mapping_model',
        'optional' => false,
    ],

    // Giving each counterparty a tax category and a tag.
    'statement_classification' => [
        'setting_key' => 'ai.statement_classification_model',
        'optional' => false,
    ],

    // Reading an identity card photograph — a vision job, so this must resolve to a model that can
    // see an image.
    'id_card' => [
        'setting_key' => 'ai.id_card_model',
        'optional' => false,
    ],

    // Reading a paragraph of law to pull a fiscal parameter from it.
    'law_reader' => [
        'setting_key' => 'ai.law_reader_model',
        'optional' => false,
    ],

    // An optional second opinion on the law, asked after the first and approved by a person. Off
    // when unset, rather than falling back to the first reader's model.
    'law_second_reader' => [
        'setting_key' => 'ai.law_second_reader_model',
        'optional' => true,
    ],
];
