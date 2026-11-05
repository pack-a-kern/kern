<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Kern\Notifications\Tests\Fixtures\Orders;
use Kern\Notifications\Tests\Fixtures\OrderShipped;
use Kern\Notifications\Tests\Fixtures\Person;

/**
 * The bell, in an application that has nothing of the one this came from: people with integer ids,
 * one kind of notice, and words written on each request from what was stored.
 */
beforeEach(function (): void {
    app(Orders::class)->carriers = [1042 => 'Northwind Freight', 1043 => 'Contoso Post'];
});

it('installs its tables beside an application of its own', function (): void {
    expect(Schema::hasTable('notifications'))->toBeTrue()
        ->and(Schema::hasTable('notification_devices'))->toBeTrue()
        ->and(Schema::hasTable('notification_preferences'))->toBeTrue()
        ->and(Schema::hasTable('users'))->toBeFalse();
});

it('stores only what the notice was given, and writes the words when the bell is opened', function (): void {
    $ada = Person::query()->create(['name' => 'Ada']);
    $ada->notify(OrderShipped::about(1042));

    expect(DB::table('notifications')->value('data'))->toBe('{"order":1042,"delayed":false}');

    $this->actingAs($ada)->getJson('/api/v1/notifications?locale=en')
        ->assertOk()
        ->assertJsonPath('unread', 1)
        ->assertJsonPath('notifications.0.type', 'order-shipped')
        ->assertJsonPath('notifications.0.title', 'Order #1042 has shipped')
        ->assertJsonPath('notifications.0.body', 'With Northwind Freight')
        ->assertJsonPath('notifications.0.url', '/orders/1042')
        ->assertJsonPath('notifications.0.level', 'info');

    $this->actingAs($ada)->getJson('/api/v1/notifications?locale=ro')
        ->assertJsonPath('notifications.0.title', 'Comanda #1042 a plecat');
});

it('marks what went wrong, newest first', function (): void {
    $ada = Person::query()->create(['name' => 'Ada']);
    $ada->notify(OrderShipped::about(1042));
    $ada->notify(OrderShipped::about(1043, delayed: true));

    $this->actingAs($ada)->getJson('/api/v1/notifications')
        ->assertJsonPath('notifications.0.title', 'Order #1043 has shipped')
        ->assertJsonPath('notifications.0.level', 'error')
        ->assertJsonPath('notifications.1.title', 'Order #1042 has shipped');
});

it('keeps one notice for each person told at once', function (): void {
    $ada = Person::query()->create(['name' => 'Ada']);
    $grace = Person::query()->create(['name' => 'Grace']);

    Notification::send([$ada, $grace], OrderShipped::about(1042));

    expect(DB::table('notifications')->distinct()->count('id'))->toBe(2)
        ->and(DB::table('notifications')->pluck('notifiable_id')->sort()->values()->all())->toBe([$ada->id, $grace->id]);
});

it('leaves out a notice whose subject is gone, or whose kind nobody registers any more', function (): void {
    $ada = Person::query()->create(['name' => 'Ada']);
    $ada->notify(OrderShipped::about(1042));
    $ada->notify(OrderShipped::about(9999));
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'retired-kind',
        'notifiable_type' => $ada->getMorphClass(),
        'notifiable_id' => $ada->id,
        'data' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($ada)->getJson('/api/v1/notifications')
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.url', '/orders/1042')
        // A notice left out does not light the bell.
        ->assertJsonPath('unread', 1);
});

it('shows each person their own notices only, and nothing once they are read', function (): void {
    $ada = Person::query()->create(['name' => 'Ada']);
    $grace = Person::query()->create(['name' => 'Grace']);
    $ada->notify(OrderShipped::about(1042));

    $this->actingAs($grace)->getJson('/api/v1/notifications')->assertJsonCount(0, 'notifications')->assertJsonPath('unread', 0);

    $this->actingAs($ada)->postJson('/api/v1/notifications/read')->assertNoContent();
    $this->actingAs($ada)->getJson('/api/v1/notifications')
        ->assertJsonCount(0, 'notifications')
        ->assertJsonPath('unread', 0);

    $ada->notify(OrderShipped::about(1043));
    $this->actingAs($ada)->getJson('/api/v1/notifications')
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.url', '/orders/1043');
});

it('writes nothing once everything is read', function (): void {
    $ada = Person::query()->create(['name' => 'Ada']);
    $ada->notify(OrderShipped::about(1042));
    $this->actingAs($ada)->postJson('/api/v1/notifications/read');

    DB::enableQueryLog();
    $this->actingAs($ada)->getJson('/api/v1/notifications')->assertOk();

    // The unread notices, and nothing written from them: no kind was asked.
    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'notifications'))->count())->toBe(1);
});

it('announces a notice on the socket by its id and kind alone', function (): void {
    $ada = Person::query()->create(['name' => 'Ada']);

    expect(OrderShipped::about(1042)->toBroadcast($ada)->data)->toBe([]);
});

it('answers nobody who is not signed in', function (): void {
    $this->getJson('/api/v1/notifications')->assertUnauthorized();
    $this->getJson('/api/v1/notifications/preferences')->assertUnauthorized();
    $this->postJson('/api/v1/notifications/push/devices', [])->assertUnauthorized();
});
