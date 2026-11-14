<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Settings\BrowserSettingsHttpTest;

use Kern\Audit\Models\AuditEvent;
use Kern\Legislation\Enums\UserAgentBrowser;
use Kern\Legislation\Enums\UserAgentSystem;
use Kern\Legislation\Models\UserAgent;
use Kern\Platform\BrowserWorker\BrowserEngine;
use Kern\Platform\BrowserWorker\PlaybookContext;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Repositories\SettingsRepository;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;

/**
 * The Browser card's two dialogs: the engine and the name every browser goes out as, saved together,
 * and the proxy — on with its address and credentials, off with none — and what reaches a playbook from
 * them. Every address, name and password here is made up.
 */
const FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:150.0) Gecko/20100101 Firefox/150.0';

beforeEach(function (): void {
    $this->app->instance(SubjectDekService::class, new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()));
    $this->admin = settingsAdminUser();
    UserAgent::query()->create([
        'user_agent' => FIREFOX, 'engine' => BrowserEngine::FIREFOX, 'system' => UserAgentSystem::WINDOWS,
        'browser' => UserAgentBrowser::FIREFOX, 'version' => 150, 'published_at' => '2026-11-10 00:00:00',
    ]);
});

/**
 * @param  array<string, mixed>  $values
 * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
 */
function proxy(\Tests\TestCase $test, array $values): \Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->admin, 'web')->putJson('/api/v1/settings/browser/proxy', [
        'enabled' => true, 'server' => 'http://proxy.example.test:3128', 'username' => 'demo', 'password' => 'demo-secret', 'playbooks' => ['bvb'],
        ...$values,
    ]);
}

it('turns the proxy on with its address and credentials, never showing the password', function (): void {
    $response = proxy($this, [])->assertOk();

    expect($response->json())->toMatchArray([
        'enabled' => true, 'server' => 'http://proxy.example.test:3128', 'username' => 'demo', 'password_set' => true, 'playbooks' => ['bvb'],
    ])
        ->and($response->json('available_playbooks'))->toContain('bvb', 'legislatie', 'anaf-spv')
        ->and($response->getContent())->not->toContain('demo-secret')
        ->and($this->actingAs($this->admin, 'web')->getJson('/api/v1/settings/browser/proxy')->getContent())->not->toContain('demo-secret');
});

it('refuses to turn the proxy on without each of its address, user name and password', function (string $field): void {
    proxy($this, [$field => null])->assertStatus(422)->assertJsonValidationErrors([$field]);
})->with(['server', 'username', 'password']);

it('refuses an address that is not http://host:port, and a playbook no package registered', function (): void {
    proxy($this, ['server' => 'http://demo:pw@proxy.example.test:3128'])->assertStatus(422)->assertJsonValidationErrors(['server']);
    proxy($this, ['server' => 'socks5://proxy.example.test:1080'])->assertStatus(422)->assertJsonValidationErrors(['server']);
    proxy($this, ['playbooks' => ['nowhere']])->assertStatus(422)->assertJsonValidationErrors(['playbooks.0']);
});

it('empties the address and the credentials when the proxy is turned off', function (): void {
    proxy($this, [])->assertOk();

    proxy($this, ['enabled' => false, 'server' => null, 'username' => null, 'password' => null])
        ->assertOk()
        ->assertJsonPath('enabled', false)
        ->assertJsonPath('server', null)
        ->assertJsonPath('password_set', false);

    expect(app(SettingsRepository::class)->get(SettingKey::BROWSER_PROXY_PASSWORD))->toBe('');
});

it('records each change once on the system chain, never the password', function (): void {
    proxy($this, [])->assertOk();

    $event = AuditEvent::query()->where('event_type', 'BrowserProxyReplaced')->sole();

    expect($event->subject_id)->toBeNull()
        ->and($event->actor_id)->toBe($this->admin->id)
        ->and($event->payload_hash)->not->toContain('demo-secret');
});

it('asks for the password or code again before changing the proxy or the identity', function (): void {
    withAuthenticator($this->admin);
    forgetVerification();

    proxy($this, [])->assertForbidden()->assertJsonPath('reason', 'VERIFICATION_REQUIRED');
    $this->actingAs($this->admin, 'web')
        ->putJson('/api/v1/settings/browser/identity', ['engine' => 'firefox', 'user_agent' => FIREFOX])
        ->assertForbidden()
        ->assertJsonPath('reason', 'VERIFICATION_REQUIRED');
});

it('refuses the engine, the name and the proxy in the general settings update', function (string $key): void {
    $this->actingAs($this->admin, 'web')
        ->putJson('/api/v1/settings/group/browser', ['settings' => [$key => 'x']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(["settings.{$key}"]);
})->with(['sources.browser_engine', 'sources.browser_user_agent', 'browser_worker.proxy_server', 'browser_worker.proxy_password']);

it('saves a listed name only with its own engine, and one typed by hand only on Chromium', function (): void {
    $identity = fn (string $engine, string $userAgent) => $this->actingAs($this->admin, 'web')
        ->putJson('/api/v1/settings/browser/identity', ['engine' => $engine, 'user_agent' => $userAgent]);

    $identity('chromium', FIREFOX)->assertStatus(422)->assertJsonValidationErrors(['user_agent']);
    $identity('webkit', 'Mozilla/5.0 (Typed) Demo/1.0')->assertStatus(422)->assertJsonValidationErrors(['user_agent']);
    $identity('firefox', "Firefox/150\r\nX-Injected: 1")->assertStatus(422)->assertJsonValidationErrors(['user_agent']);
    $identity('firefox', FIREFOX)->assertOk();

    expect(AuditEvent::query()->where('event_type', 'BrowserIdentityReplaced')->count())->toBe(1)
        ->and(app(SettingsRepository::class)->get(SettingKey::SOURCES_BROWSER_ENGINE))->toBe('firefox');

    $identity('chromium', 'Mozilla/5.0 (Typed) Demo/1.0')->assertOk();
});

it('lists the names by engine and then by system', function (): void {
    $this->actingAs($this->admin, 'web')->getJson('/api/v1/settings/browser/user-agents')
        ->assertOk()
        ->assertJsonPath('engines.1.engine', 'firefox')
        ->assertJsonPath('engines.1.systems.0', ['system' => 'windows', 'agents' => [['userAgent' => FIREFOX, 'browser' => 'firefox', 'version' => 150]]])
        ->assertJsonPath('engines.0.systems', []);
});

it('sends every playbook as the card says, unless the playbook names its own', function (): void {
    proxy($this, [])->assertOk();
    $context = app(PlaybookContext::class);
    $proxied = ['server' => 'http://proxy.example.test:3128', 'username' => 'demo', 'password' => 'demo-secret'];

    expect($context->dress(['name' => 'bvb'])['context'])->toBe([
        'engine' => 'chromium', 'userAgent' => config('sources.browser.user_agent'), 'proxy' => $proxied,
    ])
        // Not ticked: no proxy.
        ->and($context->dress(['name' => 'legislatie'])['context'])->not->toHaveKey('proxy')
        // Its own name, device or proxy stays its own; an engine is never added beside a name it did not choose.
        ->and($context->dress(['name' => 'bvb', 'context' => ['device' => 'iPhone 13', 'proxy' => ['server' => 'http://own.example.test:8000']]])['context'])
        ->toBe(['device' => 'iPhone 13', 'proxy' => ['server' => 'http://own.example.test:8000']]);
});
