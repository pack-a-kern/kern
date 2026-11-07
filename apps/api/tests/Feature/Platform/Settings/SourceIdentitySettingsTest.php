<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Settings\SourceIdentitySettingsTest;

use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Kern\Legislation\Config\OfficialSourceRegistry;

/**
 * How the portal is read, and what this installation calls itself to the sources — set on the screen,
 * refused there when a value could not be sent as written, and in force in a queue worker that was
 * already running when it was saved.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    $this->admin = settingsAdminUser();
});

it('accepts each way of reading the portal it knows, and no other', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/settings/group/legislation_portal', ['settings' => ['legislation.portal_search' => 'browser', 'legislation.portal_pages' => 'browser']])
        ->assertOk();

    $this->actingAs($this->admin)
        ->putJson('/api/v1/settings/group/legislation_portal', ['settings' => ['legislation.portal_search' => 'curl']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['settings.legislation.portal_search']);
});

it('refuses a name that would not go out in a header as written', function (string $name): void {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/settings/group/sources', ['settings' => ['sources.crawler_name' => $name]])
        ->assertUnprocessable();
})->with([
    'a second header' => ["Kern/1.0\r\nX-Injected: yes"],
    'a line break' => ["Kern/1.0\nmore"],
    'outside ASCII' => ['Kern/1.0 ăîș'],
    'too long' => [str_repeat('a', 256)],
]);

it('takes an emptied name as going back to the default, never as an empty header', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/settings/group/sources', ['settings' => ['sources.crawler_name' => '   ']])
        ->assertOk();

    expect(app(OfficialSourceRegistry::class)->find('ANAF')->userAgent)->toStartWith('Kern-RO-SourceCrawler/1.0');
});

it('takes a contact as an e-mail address or an https address only', function (): void {
    foreach (['ops@example.ro', 'https://example.ro/contact'] as $contact) {
        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/group/sources', ['settings' => ['sources.crawler_contact' => $contact]])
            ->assertOk();
    }

    foreach (['http://example.ro', 'not a contact', "ops@example.ro\r\nX: y"] as $contact) {
        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/group/sources', ['settings' => ['sources.crawler_contact' => $contact]])
            ->assertUnprocessable();
    }
});

it('names the crawler with its contact to a source that has no name of its own to be called by', function (): void {
    config(['sources.crawler.name' => 'Kern-RO-SourceCrawler/1.0', 'sources.crawler.contact' => 'ops@example.ro']);

    $registry = app(OfficialSourceRegistry::class);

    expect($registry->find('ANAF')->userAgent)->toBe('Kern-RO-SourceCrawler/1.0 (contact: ops@example.ro)')
        // The portal keeps its own: it refuses a client that does not look like a browser.
        ->and($registry->find('LEGISLATIE')->userAgent)->toStartWith('Mozilla/5.0');

    config(['sources.crawler.contact' => '']);

    expect($registry->find('ANAF')->userAgent)->toBe('Kern-RO-SourceCrawler/1.0');
});

it('reaches a queue worker that was running when it was saved, and leaves it when cleared', function (): void {
    $default = config('sources.crawler.name');

    $this->actingAs($this->admin)
        ->putJson('/api/v1/settings/group/sources', ['settings' => ['sources.crawler_name' => 'Kern-Test/2.0']])
        ->assertOk();

    // A worker booted before the save: it still holds what it booted with until its loop turns.
    config(['sources.crawler.name' => $default]);
    Event::dispatch(new Looping('redis', 'default'));

    expect(config('sources.crawler.name'))->toBe('Kern-Test/2.0');

    $this->actingAs($this->admin)
        ->putJson('/api/v1/settings/group/sources', ['settings' => ['sources.crawler_name' => null]])
        ->assertOk();
    Event::dispatch(new Looping('redis', 'default'));

    expect(config('sources.crawler.name'))->toBe($default);
});

it('holds a name or contact from `.env` to the same rules as one typed on the screen', function (string $key, string $value): void {
    config([$key => $value]);

    expect(fn () => app(OfficialSourceRegistry::class)->find('ANAF'))->toThrow(\RuntimeException::class);
})->with([
    'a name with a line break' => ['sources.crawler.name', "Kern/1.0\r\nX-Injected: yes"],
    'a contact that is neither' => ['sources.crawler.contact', 'call me'],
    'a portal agent with a line break' => ['sources.entries.ANAF.user_agent', "Mozilla\nX: y"],
]);
