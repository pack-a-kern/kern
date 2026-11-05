<?php

declare(strict_types=1);

use Kern\Notifications\DataTransferObjects\Message;
use Kern\Notifications\Models\Device;
use Kern\Notifications\Services\PushSender;
use Kern\Notifications\Testing\BrowserSubscription;
use Kern\Notifications\Testing\FakePushService;
use Kern\Notifications\Tests\Fixtures\Orders;
use Kern\Notifications\Tests\Fixtures\OrderShipped;
use Kern\Notifications\Tests\Fixtures\Person;
use Minishlink\WebPush\VAPID;

/**
 * Switches and sending: what reaches a device, in whose words, and what happens to one that is gone.
 */
beforeEach(function (): void {
    $keys = VAPID::createVapidKeys();
    config(['notifications.push.vapid.public_key' => $keys['publicKey'], 'notifications.push.vapid.private_key' => $keys['privateKey']]);
    app(Orders::class)->carriers = [1042 => 'Northwind Freight'];

    $this->service = new FakePushService();
    $this->app->instance(PushSender::class, new PushSender($this->service));
});

it('lists one switch per registered kind, in the page’s language, and remembers a choice', function (): void {
    $ada = Person::query()->create(['name' => 'Ada']);

    $this->actingAs($ada)->getJson('/api/v1/notifications/preferences?locale=ro')
        ->assertOk()
        ->assertJsonPath('configured', true)
        ->assertJsonPath('types.0.key', 'order-shipped')
        ->assertJsonPath('types.0.label', 'Comenzi expediate')
        ->assertJsonPath('types.0.hint', 'Când o comandă pleacă din depozit.')
        ->assertJsonPath('types.0.enabled', true);

    $this->actingAs($ada)->patchJson('/api/v1/notifications/preferences', ['type' => 'order-shipped', 'enabled' => false])->assertOk();
    $this->actingAs($ada)->getJson('/api/v1/notifications/preferences')->assertJsonPath('types.0.enabled', false);

    $this->actingAs($ada)->patchJson('/api/v1/notifications/preferences', ['type' => 'no-such-kind', 'enabled' => true])->assertStatus(422);
});

it('sends a notice to the person’s devices in the bell’s words, encrypted to each', function (): void {
    $ada = Person::query()->create(['name' => 'Ada']);
    $this->actingAs($ada)->postJson('/api/v1/notifications/push/devices', BrowserSubscription::make());

    $ada->notify(OrderShipped::about(1042));

    expect($this->service->received)->toHaveCount(1)
        ->and((string) $this->service->received[0]->getBody())->not->toContain('Northwind')
        ->and($this->service->received[0]->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
        ->and($this->service->received[0]->getHeaderLine('Authorization'))->toStartWith('vapid ')
        ->and(Device::query()->sole()->last_used_at)->not->toBeNull();
});

it('sends nothing to the devices of someone who turned the kind off — the bell still has it', function (): void {
    $ada = Person::query()->create(['name' => 'Ada']);
    $this->actingAs($ada)->postJson('/api/v1/notifications/push/devices', BrowserSubscription::make());
    $this->actingAs($ada)->patchJson('/api/v1/notifications/preferences', ['type' => 'order-shipped', 'enabled' => false]);

    $ada->notify(OrderShipped::about(1042));

    expect($this->service->received)->toBeEmpty()
        ->and($ada->notifications()->count())->toBe(1);
});

it('forgets a device the push service says is gone', function (): void {
    $this->service = new FakePushService([201, 410]);
    $ada = Person::query()->create(['name' => 'Ada']);
    $this->actingAs($ada)->postJson('/api/v1/notifications/push/devices', BrowserSubscription::make('https://fcm.googleapis.com/fcm/send/kept'));
    $this->actingAs($ada)->postJson('/api/v1/notifications/push/devices', BrowserSubscription::make('https://fcm.googleapis.com/fcm/send/gone'));

    $sent = (new PushSender($this->service))->send($ada, static fn (string $locale): Message => new Message('Order #1042 has shipped', 'With Northwind Freight'));

    expect($sent)->toBe(1)
        ->and(Device::query()->count())->toBe(1);
});

it('sends a test on request, and says when this server has no keys', function (): void {
    $ada = Person::query()->create(['name' => 'Ada']);
    $this->actingAs($ada)->postJson('/api/v1/notifications/push/devices', BrowserSubscription::make());

    $this->actingAs($ada)->postJson('/api/v1/notifications/push/test')->assertOk()->assertJson(['delivered' => 1]);

    config(['notifications.push.vapid.public_key' => '', 'notifications.push.vapid.private_key' => '']);
    $this->actingAs($ada)->getJson('/api/v1/notifications/preferences')->assertJson(['configured' => false, 'public_key' => null]);
    $this->actingAs($ada)->postJson('/api/v1/notifications/push/test')->assertStatus(409);
});
