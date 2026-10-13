<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Repositories\SettingsRepository;
use Kern\TaxRomania\Fx\Services\CurrencyCatalogue;

/**
 * Which currencies the application works in.
 *
 * The property under test is the separation the class exists for: what BNR publishes is not what the
 * forms offer, and the setting changes only the second.
 */
it('offers what the setting names, with the base currency first', function (): void {
    Config::set('sources.fx.currencies', 'usd, eur ,USD');

    // Upper-cased, deduplicated, and RON in front — none of which the stored string said.
    expect(app(CurrencyCatalogue::class)->enabled())->toBe(['RON', 'USD', 'EUR']);
});

it('keeps the base currency even when the setting leaves it out', function (): void {
    // A Romanian tax application without lei is a broken install, not a configuration: the fiscal
    // parameters are in RON and every foreign amount converts to it.
    Config::set('sources.fx.currencies', 'EUR');

    expect(app(CurrencyCatalogue::class)->enabled())->toBe(['RON', 'EUR']);
});

it('falls back to the base currency alone rather than to nothing', function (): void {
    // A select with no options is a form nobody can submit.
    Config::set('sources.fx.currencies', '');

    expect(app(CurrencyCatalogue::class)->enabled())->toBe(['RON']);
});

it('reads the setting a person saved, not only the environment default', function (): void {
    // The overlay is what makes a saved setting mean anything; this is the half that proves this
    // key took part in it rather than being written to a row nothing reads.
    app(SettingsRepository::class)->set(SettingKey::FX_CURRENCIES, 'EUR,JPY', null);
    app(\Kern\Platform\Settings\Services\StoredSettingsOverlay::class)->apply();

    expect(app(CurrencyCatalogue::class)->enabled())->toBe(['RON', 'EUR', 'JPY']);
});

it('offers every currency there is a rate for, whatever the setting says', function (): void {
    // The import is deliberately unfiltered: BNR publishes thirty-nine codes and all of them are
    // stored, so the settings screen can offer what this installation can genuinely convert.
    Config::set('sources.fx.currencies', 'EUR');

    expect(app(CurrencyCatalogue::class)->available())->toContain('RON');
});
