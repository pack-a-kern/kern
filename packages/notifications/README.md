# kern/notifications

Notices for a Laravel application: a **bell** inside it, and the **device's own notifications** outside
it (Web Push: Android, desktop browsers, and iPhone or iPad apps added to the Home Screen) — from
**one class per kind of notice**, with **a switch per kind** for each person.

```
  $person->notify(OrderShipped::about($order));
                    │
        ┌───────────┼──────────────────────────┐
        ▼           ▼                          ▼
     the bell     the socket                 the person's devices
  (stored: ids)   (id + kind only)           (if their switch for the kind is on)
        │           │                          │
        └─────┬─────┘                          │
              ▼                                ▼
   OrderShipped::render($notices, $person, 'en') — the same words in both places,
   written when the notice is shown, kept nowhere
```

## What it gives you

- **One class per kind.** Its name, the line under its switch, and what one notice says — all in it.
  Register it once; the bell, the devices and the switches find it there. The page has no list of kinds.
- **Words written on demand.** A notice is stored with ids and codes only. Its title, text and link are
  written each time it is shown, in the reader's language, so they may say what the table must not hold —
  an amount, a name — and they are the same in the bell and on a phone.
- **A bell of unread notices.** Opening it writes only those; once everything is read it writes nothing.
- **A switch per kind**, per person, for their devices. The bell shows every unread notice regardless.
- **Their devices**, listed by what a person recognises them by, removable one by one, with a test send.
- **Safe by default.** Device addresses must be a real push service; push addresses and keys are
  encrypted; messages are encrypted to the device; links stay inside the application. See
  [Security](#security).
- **Any Laravel application.** Any Eloquent model can be notified, with integer or UUID keys. No other
  package of this repository is needed.

## Installing

```bash
composer require kern/notifications
php artisan migrate
php artisan notifications:keys      # prints WEB_PUSH_PUBLIC_KEY and WEB_PUSH_PRIVATE_KEY
```

Put the two printed lines in `.env`. That is all the server needs: the provider is discovered, and the
migrations, routes, translations and the command come with it.

The `notifications` table is Laravel's own; if the application already has it, the package keeps it.
Its `notifiable` columns follow the application's morph key type — call `Schema::morphUsingUuids()` in a
service provider if people's keys are UUIDs.

## Declaring a kind of notice

A kind is a class implementing `Contracts\NoticeType`. This one tells a customer their order has left
the warehouse:

```php
namespace App\Notices;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Kern\Notifications\Contracts\NoticeType;
use Kern\Notifications\DataTransferObjects\Message;
use Kern\Notifications\Enums\Level;
use Kern\Notifications\Notice;

final readonly class OrderShipped implements NoticeType
{
    /** Stored with every notice and every person's choice: never rename it once shipped. */
    public static function key(): string
    {
        return 'order-shipped';
    }

    /** How the application sends one. Only ids and codes go in: this is stored as plain text. */
    public static function about(Order $order): Notice
    {
        return new Notice(self::class, ['order_id' => $order->id]);
    }

    public function label(string $locale): string
    {
        return __('notices.order_shipped.label', [], $locale);     // "Orders shipped"
    }

    public function hint(string $locale): string
    {
        return __('notices.order_shipped.hint', [], $locale);      // "When an order leaves the warehouse."
    }

    /**
     * What each notice says to `$person`, by notice id — all of them at once, so each lookup is one
     * query however many there are. One left out of the answer is not shown.
     */
    public function render(array $notices, Model $person, string $locale): array
    {
        $orders = Order::query()
            ->whereKey(array_column($notices, 'order_id'))
            ->whereBelongsTo($person, 'customer')   // no longer theirs: a notice is not a way to keep reading it
            ->get()
            ->keyBy('id');

        $messages = [];

        foreach ($notices as $id => $data) {
            $order = $orders->get($data['order_id'] ?? null);

            if ($order === null) {
                continue;   // gone, or not theirs
            }

            $messages[$id] = new Message(
                title: __('notices.order_shipped.title', ['number' => $order->number], $locale),  // "Order #1042 has shipped"
                body: __('notices.order_shipped.body', ['carrier' => $order->carrier], $locale),  // "With Northwind Freight"
                url: "/orders/{$order->number}",
                level: $order->delayed ? Level::Error : Level::Info,
                tag: "order-{$order->id}",   // a newer notice about this order replaces the older one on a device
            );
        }

        return $messages;
    }
}
```

Register it in a service provider's `boot()`:

```php
use Kern\Notifications\Services\NoticeTypes;

public function boot(NoticeTypes $types): void
{
    $types->register(OrderShipped::class);
}
```

The class is resolved from the container each time it is used, so its constructor may take whatever it
needs — a repository, a gate, a formatter.

## Sending one

Like any Laravel notification:

```php
$customer->notify(OrderShipped::about($order));

Notification::send($warehouseTeam, OrderShipped::about($order));
```

`Notice` goes three ways, always the same three: **stored** for the bell (`data` only), **announced** on
the person's private broadcast channel (its id and kind only — the page fetches the words over HTTPS),
and **sent** to the person's devices when their switch for the kind is on.

## The endpoints

All under `api/v1/notifications` (`notifications.routes`), for the signed-in person only. `?locale=`
chooses the language the words are written in.

| Method | Path | What it does |
|---|---|---|
| `GET` | `/` | the unread notices, in words, and how many |
| `POST` | `/read` | marks them all read — the bell is then empty |
| `GET` | `/preferences` | whether this server sends to devices, its public key, and one switch per kind |
| `PATCH` | `/preferences` | `{type, enabled}` — turns a kind on or off for the person's devices |
| `GET` | `/push/devices` | the person's devices — never their addresses or keys |
| `POST` | `/push/devices` | turns notices on for this browser (throttled) |
| `DELETE` | `/push/devices/{id}` | turns them off for one device (throttled) |
| `POST` | `/push/test` | sends a test to every device of the person (throttled harder) |

The bell, as `GET /?locale=en` returns it:

```json
{
  "notifications": [
    {
      "id": "0f9c2a8e-8c3e-4d7a-9a51-3b6f1e2d4c10",
      "type": "order-shipped",
      "title": "Order #1042 has shipped",
      "body": "With Northwind Freight",
      "url": "/orders/1042",
      "level": "info",
      "tag": "order-1042",
      "created_at": "2026-11-05T09:00:00+00:00"
    }
  ],
  "unread": 1
}
```

The switches, as `GET /preferences?locale=en` returns them:

```json
{
  "configured": true,
  "public_key": "<the server's public key>",
  "types": [
    { "key": "order-shipped", "label": "Orders shipped", "hint": "When an order leaves the warehouse.", "enabled": true }
  ]
}
```

## The browser side

The package ships PHP, never pages: the bell and the settings screen are the application's, in whatever
it is built with. They only display what the endpoints return. Three pieces are needed.

**1. Turning notices on for this browser** — from a button, since a browser asks for the permission only
in answer to a tap:

```js
async function turnOn(publicKey, locale) {
    if ((await Notification.requestPermission()) !== 'granted') return;

    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: base64UrlToBytes(publicKey), // the `public_key` from GET /preferences
    });

    await fetch('/api/v1/notifications/push/devices', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        body: JSON.stringify({ ...subscription.toJSON(), locale }),
    });
}
```

(`base64UrlToBytes` and `csrfToken` stand for the application's own small helpers.)

**2. The service worker** — shows what arrives, and opens only paths of its own origin:

```js
self.addEventListener('push', (event) => {
    const message = event.data ? event.data.json() : {};

    event.waitUntil(
        self.registration.showNotification(message.title ?? 'Notice', {
            body: message.body ?? '',
            tag: message.tag ?? undefined,
            data: { url: message.url ?? '/' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data.url, self.location.origin);
    const url = target.origin === self.location.origin ? target.href : self.location.origin + '/';
    event.waitUntil(self.clients.openWindow(url));
});
```

**3. The bell** — `GET /` when it opens, and again when the person's private channel announces a notice
(with Laravel Echo: `Echo.private(channel).notification(({ id }) => refetch())`).

**iPhone and iPad** give web notifications only to an app **added to the Home Screen** (iOS 16.4 and
later). In a Safari tab, `PushManager` is simply missing: tell the person to add the app first, rather
than that their browser cannot.

## Fitting it to the application

| To | Do |
|---|---|
| encrypt devices with your own keys | extend `Models\Device`, cast `endpoint`, `public_key`, `auth_token` your way, then `Notifications::useDeviceModel(YourDevice::class)` |
| name devices ("Safari on iOS") | bind `Contracts\DescribesDevices`; with nothing bound a device has no name |
| keep an audit trail | listen to `Events\DeviceAdded` and `Events\DeviceRemoved` |
| delete an account | call `Actions\ForgetNotifiable` — there is no foreign key to do it for you |
| mount the endpoints elsewhere | `NOTIFICATIONS_ROUTE_PREFIX`, or publish the config (`--tag=notifications-config`) and change `routes.middleware` |

## Security

- **A device cannot make the server call an address of its choosing.** A push address is accepted only
  over `https`, on port 443, with no credentials in it, on one of the browsers' push services
  (`notifications.push.hosts`: Google, Apple, Mozilla, Microsoft) — checked when it is stored and again
  before every send.
- **A copy of the table is not a way to send.** The push address and both keys are encrypted at rest; a
  device is found again by a SHA-256 of its address.
- **The push service cannot read a notice.** Each message is encrypted to its one device (RFC 8291) and
  signed by the server (VAPID, RFC 8292), by `minishlink/web-push` — no cryptography is written here.
- **Nothing a person would mind is stored or broadcast.** The table keeps what `about()` put in; the
  socket carries an id and a kind; the words exist only in the response that shows them.
- **A notice links only inside the application.** `Message` refuses an absolute address, `//host` and
  backslashes; the service worker sketch above opens only its own origin.
- **Only the person's own.** Every endpoint answers for the signed-in person; another person's device is
  "not found", like one that never existed.
- **Throttled.** Device changes: 10 per 5 minutes per person. Test sends: 3 per minute. At most 10
  devices per person.
- **Quiet logs.** A refused send is logged by its status code, a failed one by its exception class —
  never the address, which is the device's secret.
- A device the push service reports gone (404, 410) is deleted, and not sent to again.

## Configuration

| Variable | Default | |
|---|---|---|
| `WEB_PUSH_PUBLIC_KEY`, `WEB_PUSH_PRIVATE_KEY` | empty | the pair `notifications:keys` prints; empty turns device notices off |
| `WEB_PUSH_SUBJECT` | `APP_URL` | a `mailto:` or `https:` address the push services may contact |
| `WEB_PUSH_TTL_SECONDS` | `86400` | how long a push service holds a notice for a device that is off |
| `WEB_PUSH_MAX_DEVICES` | `10` | devices per person |
| `NOTIFICATIONS_ROUTE_PREFIX` | `api/v1/notifications` | where the endpoints hang |
| `NOTIFICATIONS_BELL_LIMIT` | `20` | how many unread notices the bell lists at most |

A new key pair stops every device subscribed under the old one until it turns notices on again.

## Testing your kinds

The package ships two test doubles:

```php
use Kern\Notifications\Services\PushSender;
use Kern\Notifications\Testing\BrowserSubscription;
use Kern\Notifications\Testing\FakePushService;

$service = new FakePushService([201, 410]);               // the statuses it answers, in order
$this->app->instance(PushSender::class, new PushSender($service));

$this->actingAs($customer)->postJson('/api/v1/notifications/push/devices', BrowserSubscription::make());
$customer->notify(OrderShipped::about($order));

expect($service->received)->toHaveCount(1);               // the encrypted request that would have gone out
```

`BrowserSubscription::make()` is what a browser's `subscribe()` returns, with a real P-256 key, so the
message is actually encrypted.

## Running its own suite

```bash
composer install
vendor/bin/pest
```

`tests/Integration` boots Laravel with this package and nothing else — people with integer ids in a
`people` table, and the `OrderShipped` kind above — on Postgres. `tests/Unit` is plain PHP.
