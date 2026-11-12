<?php

declare(strict_types=1);

namespace Tests\Feature\Legislation\Retrieval\SearchSettingsTest;

use Kern\Legislation\Retrieval\Embedding\EmbeddingProvider;
use Kern\Legislation\Retrieval\Query\SearchSettings;
use RuntimeException;

it('reads how a search ranks from the configuration', function (): void {
    $settings = SearchSettings::read(config());

    expect($settings->weightMeaning)->toBe(0.5)->and($settings->results)->toBe(20)->and($settings->maxResults)->toBe(50);
});

it('fails, naming the variable, when a ranking value is missing or out of range', function (string $key, mixed $value, string $variable): void {
    config()->set("legislation.search.{$key}", $value);

    expect(fn () => SearchSettings::read(config()))->toThrow(RuntimeException::class, $variable);
})->with([
    'a weight missing' => ['weight_words', null, 'LEGISLATION_SEARCH_WEIGHT_WORDS'],
    'a minimum above one' => ['min_meaning', '1.5', 'LEGISLATION_SEARCH_MIN_MEANING'],
    'more shown than allowed' => ['results', '60', 'LEGISLATION_SEARCH_RESULTS'],
]);

it('fails when the switch for searching by meaning is neither on nor off', function (): void {
    config()->set('ai.embeddings.enabled', null);
    app()->forgetInstance(EmbeddingProvider::class);

    expect(fn () => app(EmbeddingProvider::class))->toThrow(RuntimeException::class, 'RETRIEVAL_EMBEDDING_ENABLED');
});
