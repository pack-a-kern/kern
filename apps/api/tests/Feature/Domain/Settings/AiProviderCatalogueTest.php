<?php

declare(strict_types=1);

use App\Domain\Settings\DataTransferObjects\AiModelListShape;
use App\Domain\Settings\DataTransferObjects\AiProviderPreset;
use App\Domain\Settings\Enums\AiDialect;
use App\Domain\Settings\Services\AiProviderCatalogue;
use Illuminate\Support\Facades\Config;

it('exposes every configured preset with a usable shape', function (): void {
    $presets = app(AiProviderCatalogue::class)->all();

    expect($presets)->not->toBeEmpty();

    foreach ($presets as $preset) {
        expect($preset->key)->not->toBe('')
            ->and($preset->label)->not->toBe('');

        // A preset that requires an API key but prefills no base URL would leave the operator with
        // nothing to act on; `custom` is the deliberate exception (they supply their own URL).
        if ($preset->requiresApiKey) {
            expect($preset->baseUrl)->not->toBeNull();
        }
    }
});

it('serves Anthropic with its own dialect, because its API is not OpenAI-compatible', function (): void {
    $catalogue = app(AiProviderCatalogue::class);

    expect($catalogue->dialectFor('anthropic'))->toBe(AiDialect::ANTHROPIC)
        ->and($catalogue->dialectFor('openai'))->toBe(AiDialect::OPENAI)
        ->and($catalogue->dialectFor('ollama'))->toBe(AiDialect::OPENAI);
});

it('falls back to the OpenAI dialect for a provider name that matches no preset', function (): void {
    // An operator who configured AI through env before presets existed, or who removed a preset
    // from config after saving it, must not have the connection test throw.
    $catalogue = app(AiProviderCatalogue::class);

    expect($catalogue->dialectFor('some-internal-gateway'))->toBe(AiDialect::OPENAI)
        ->and($catalogue->dialectFor(null))->toBe(AiDialect::OPENAI)
        ->and($catalogue->find('some-internal-gateway'))->toBeNull();
});

it('throws on a malformed preset rather than silently serving a broken one', function (): void {
    Config::set('ai_providers.presets', ['broken' => ['label' => 'Broken']]);

    expect(fn () => app(AiProviderCatalogue::class)->all())
        ->toThrow(RuntimeException::class, 'broken');
});

it('does not leak the models endpoint path to the client', function (): void {
    $openai = app(AiProviderCatalogue::class)->find('openai');

    expect($openai)->toBeInstanceOf(AiProviderPreset::class);

    // Narrowed for PHPStan; the expectation above is what actually fails the test if it is null.
    assert($openai instanceof AiProviderPreset);

    expect($openai->toArray())->not->toHaveKey('models_path')
        ->and($openai->toArray()['can_list_models'])->toBeTrue();
});

it('extracts model identifiers from both provider response shapes', function (): void {
    expect(AiModelListShape::DATA_ID->extract(['data' => [['id' => 'b'], ['id' => 'a']]]))
        ->toBe(['a', 'b'])
        ->and(AiModelListShape::MODELS_NAME->extract(['models' => [['name' => 'llama3.2:3b']]]))
        ->toBe(['llama3.2:3b']);
});

it('ignores unusable rows instead of failing the whole list', function (): void {
    $models = AiModelListShape::DATA_ID->extract([
        'data' => [['id' => 'good'], ['id' => ''], ['id' => 42], 'not-an-object', ['id' => 'good']],
    ]);

    expect($models)->toBe(['good']);
});

it('returns nothing for a body that is not a model list at all', function (): void {
    expect(AiModelListShape::DATA_ID->extract(['error' => 'nope']))->toBe([])
        ->and(AiModelListShape::DATA_ID->extract(['data' => 'not-a-list']))->toBe([]);
});
