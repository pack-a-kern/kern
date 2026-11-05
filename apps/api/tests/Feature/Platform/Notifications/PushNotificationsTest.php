<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Notifications\PushNotificationsTest;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Identity\Actions\EraseUser;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Notifications\DataTransferObjects\PushMessage;
use Kern\Platform\Notifications\Models\PushSubscription;
use Kern\Platform\Notifications\Services\NotificationPreferences;
use Kern\Platform\Notifications\Services\PushSender;
use Minishlink\WebPush\VAPID;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Notifications on a person's devices: their devices and choices, the guard on where a device may point
 * the server, and the send itself — encrypted to the device, and a device the service says is gone
 * removed.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

/**
 * A browser's subscription as `PushManager.subscribe()` returns it: a real P-256 key and an auth secret.
 *
 * @return array{endpoint: string, keys: array{p256dh: string, auth: string}, locale: string}
 */
function browserSubscription(string $endpoint = 'https://fcm.googleapis.com/fcm/send/device-token-1'): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    assert($key !== false);
    $details = openssl_pkey_get_details($key);
    assert(is_array($details));
    $point = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $encode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    return ['endpoint' => $endpoint, 'keys' => ['p256dh' => $encode($point), 'auth' => $encode(random_bytes(16))], 'locale' => 'ro'];
}

function configurePush(): void
{
    $keys = VAPID::createVapidKeys();
    config([
        'notifications.push.vapid.public_key' => $keys['publicKey'],
        'notifications.push.vapid.private_key' => $keys['privateKey'],
    ]);
}

/** A push service that answers each request with the next status, and keeps what it was sent. */
final class FakePushService implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $received = [];

    /** @param list<int> $statuses */
    public function __construct(private array $statuses)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->received[] = $request;

        return new Response(array_shift($this->statuses) ?? 201);
    }
}

it('turns notifications on for a device, keeping its address and keys encrypted', function (): void {
    $user = User::factory()->create();
    $subscription = browserSubscription();

    $this->actingAs($user)->postJson('/api/v1/notifications/push/devices', $subscription)->assertCreated();

    $row = DB::table('push_subscriptions')->where('user_id', $user->id)->sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->endpoint_hash)->toBe(hash('sha256', $subscription['endpoint']))
        ->and($row->endpoint_ciphertext)->not->toContain('fcm.googleapis.com')
        ->and($row->public_key_ciphertext)->not->toContain($subscription['keys']['p256dh'])
        ->and($row->auth_token_ciphertext)->not->toContain($subscription['keys']['auth'])
        ->and(PushSubscription::query()->where('user_id', $user->id)->sole()->endpoint_ciphertext)->toBe($subscription['endpoint']);
});

it('refuses an address that is not a browser push service', function (string $endpoint): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->postJson('/api/v1/notifications/push/devices', browserSubscription($endpoint))
        ->assertStatus(422)
        ->assertJsonValidationErrors('endpoint');

    expect(PushSubscription::query()->where('user_id', $user->id)->exists())->toBeFalse();
})->with([
    'plain http' => ['http://fcm.googleapis.com/fcm/send/x'],
    'an internal address' => ['https://10.0.0.5/push'],
    'this server' => ['https://localhost/api/v1/admin/users'],
    'a look-alike host' => ['https://fcm.googleapis.com.evil.example/x'],
    'another port' => ['https://fcm.googleapis.com:8443/x'],
    'credentials in it' => ['https://user:pass@fcm.googleapis.com/x'],
]);

it('treats the same browser subscribing again as the same device', function (): void {
    $user = User::factory()->create();
    $subscription = browserSubscription();

    $first = $this->actingAs($user)->postJson('/api/v1/notifications/push/devices', $subscription)->json('id');
    $this->actingAs($user)->postJson('/api/v1/notifications/push/devices', $subscription)->assertOk()->assertJson(['id' => $first]);

    expect(PushSubscription::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('gives a device to whoever signs in on it, never to both', function (): void {
    $subscription = browserSubscription();
    $this->actingAs(User::factory()->create())->postJson('/api/v1/notifications/push/devices', $subscription)->assertCreated();

    $next = User::factory()->create();
    $this->actingAs($next)->postJson('/api/v1/notifications/push/devices', $subscription)->assertCreated();

    expect(PushSubscription::query()->where('endpoint_hash', hash('sha256', $subscription['endpoint']))->sole()->user_id)->toBe($next->id);
});

it('lists and removes only the account’s own devices', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($owner)->postJson('/api/v1/notifications/push/devices', browserSubscription('https://fcm.googleapis.com/fcm/send/a'));
    $theirs = $this->actingAs($other)->postJson('/api/v1/notifications/push/devices', browserSubscription('https://web.push.apple.com/b'))->json('id');
    assert(is_string($theirs));

    $listed = $this->actingAs($owner)->getJson('/api/v1/notifications/push/devices')->assertOk();
    expect($listed->json('devices'))->toHaveCount(1)
        ->and(array_keys((array) $listed->json('devices.0')))->not->toContain('endpoint');

    $this->actingAs($owner)->deleteJson('/api/v1/notifications/push/devices/' . $theirs)->assertNotFound();
    expect(PushSubscription::query()->whereKey($theirs)->exists())->toBeTrue();
});

it('stops at the configured number of devices per account', function (): void {
    config(['notifications.push.max_devices_per_user' => 1]);
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/api/v1/notifications/push/devices', browserSubscription('https://fcm.googleapis.com/fcm/send/a'))->assertCreated();

    $this->actingAs($user)->postJson('/api/v1/notifications/push/devices', browserSubscription('https://fcm.googleapis.com/fcm/send/b'))
        ->assertStatus(422)->assertJsonValidationErrors('endpoint');
});

it('lists the kinds packages declare, in both languages, and remembers a choice', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/notifications/preferences')->assertOk();
    $keys = array_column((array) $response->json('types'), 'key');
    expect($keys)->toContain('statement-imports', 'deposit-maturity');

    $at = array_search('deposit-maturity', $keys, true);
    expect($response->json("types.{$at}.enabled"))->toBeTrue()
        ->and($response->json("types.{$at}.label.ro"))->toBe('Scadența depozitelor')
        ->and($response->json("types.{$at}.hint.en"))->toContain('09:00');

    $this->actingAs($user)->patchJson('/api/v1/notifications/preferences', ['type' => 'deposit-maturity', 'enabled' => false])->assertOk();
    expect(app(NotificationPreferences::class)->allowsPush($user, 'deposit-maturity'))->toBeFalse()
        ->and(app(NotificationPreferences::class)->allowsPush($user, 'statement-imports'))->toBeTrue();

    $this->actingAs($user)->patchJson('/api/v1/notifications/preferences', ['type' => 'no-such-kind', 'enabled' => true])->assertStatus(422);
});

it('says when this server has no keys, and sends nothing', function (): void {
    // Stated, not assumed: an installation's own .env may well hold a pair.
    config(['notifications.push.vapid.public_key' => '', 'notifications.push.vapid.private_key' => '']);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/notifications/preferences')->assertJson(['configured' => false, 'public_key' => null]);
    $this->actingAs($user)->postJson('/api/v1/notifications/push/test')->assertStatus(409);
});

it('sends to each device encrypted, and forgets a device the service says is gone', function (): void {
    configurePush();
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/api/v1/notifications/push/devices', browserSubscription('https://fcm.googleapis.com/fcm/send/kept'));
    $this->actingAs($user)->postJson('/api/v1/notifications/push/devices', browserSubscription('https://fcm.googleapis.com/fcm/send/gone'));

    $service = new FakePushService([201, 410]);
    $sent = (new PushSender($service))->toUser($user, static fn (string $locale): PushMessage => new PushMessage('Depozit', 'BIDR: 6.123.456,78 RON'));

    expect($sent)->toBe(1)
        ->and($service->received)->toHaveCount(2)
        ->and((string) $service->received[0]->getBody())->not->toContain('BIDR')
        ->and($service->received[0]->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
        ->and($service->received[0]->getHeaderLine('Authorization'))->toStartWith('vapid ')
        ->and(PushSubscription::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(PushSubscription::query()->where('user_id', $user->id)->sole()->last_used_at)->not->toBeNull();
});

it('takes an erased account’s devices and choices with it', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/api/v1/notifications/push/devices', browserSubscription());
    app(NotificationPreferences::class)->set($user, 'deposit-maturity', false);

    app(EraseUser::class)($user, $user->id);

    expect(PushSubscription::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('notification_preferences')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('shows nothing to a visitor who is not signed in', function (): void {
    $this->getJson('/api/v1/notifications/preferences')->assertUnauthorized();
    $this->postJson('/api/v1/notifications/push/devices', browserSubscription())->assertUnauthorized();
});
