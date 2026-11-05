<?php

declare(strict_types=1);

namespace Kern\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kern\Notifications\Contracts\DescribesDevices;
use Kern\Notifications\Events\DeviceAdded;
use Kern\Notifications\Events\DeviceRemoved;
use Kern\Notifications\Http\Person;
use Kern\Notifications\Models\Device;
use Kern\Notifications\Notifications;
use Kern\Notifications\Rules\PushServiceAddress;

/**
 * `GET`, `POST /push/devices` and `DELETE /push/devices/{deviceId}` — the person's own devices that
 * receive notices.
 *
 * A device is the browser's subscription: the push service's address and two keys, which only that
 * browser can use. They are never sent back; a device is shown by what a person recognises it by, and
 * by `endpoint_hash`, which lets the page find "this device" among them without holding the address.
 */
final readonly class DeviceController
{
    public function __construct(private DescribesDevices $describer)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $devices = Notifications::devicesOf(Person::of($request))
            ->orderBy('created_at')
            ->get()
            ->map(static fn (Device $device): array => [
                'id' => $device->id,
                'endpoint_hash' => $device->endpoint_hash,
                'locale' => $device->locale,
                'device_kind' => $device->device_kind,
                'device_browser' => $device->device_browser,
                'device_platform' => $device->device_platform,
                'created_at' => $device->created_at?->toIso8601String(),
                'last_used_at' => $device->last_used_at?->toIso8601String(),
            ])
            ->values();

        return response()->json(['devices' => $devices]);
    }

    /**
     * Turns notices on for the browser that sent this. The same browser subscribing again — or after
     * someone else signed in on it — is the same device, found by its address, and now belongs to
     * whoever is signed in: a phone notifies the person using it.
     */
    public function store(Request $request): JsonResponse
    {
        $person = Person::of($request);

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', new PushServiceAddress()],
            'keys.p256dh' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'keys.auth' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'locale' => ['required', 'string', 'regex:/^[a-z]{2}$/'],
        ]);

        $model = Notifications::deviceModel();
        $hash = Device::hashEndpoint($data['endpoint']);
        $existing = $model::query()->where('endpoint_hash', $hash)->first();

        if ($existing !== null && ! $existing->notifiable()->is($person)) {
            // Its keys may be sealed for the previous person, so it cannot simply change hands.
            $existing->delete();
            $existing = null;
        }

        $limit = config()->integer('notifications.push.max_devices', 10);

        if ($existing === null && Notifications::devicesOf($person)->count() >= $limit) {
            throw ValidationException::withMessages([
                'endpoint' => [__('notifications::notifications.too_many_devices', ['count' => $limit])],
            ]);
        }

        $description = $this->describer->describe($request->userAgent());
        $device = $existing ?? new $model();
        // The owner first: a model that encrypts per person needs to know whose keys these are.
        $device->notifiable()->associate($person);
        $device->fill([
            'endpoint_hash' => $hash,
            'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'locale' => $data['locale'],
            'device_kind' => $description->kind,
            'device_browser' => $description->browser,
            'device_platform' => $description->platform,
        ])->save();

        if ($existing !== null) {
            return response()->json(['id' => $device->id]);
        }

        event(new DeviceAdded($person, $device->id));

        return response()->json(['id' => $device->id], 201);
    }

    /** Only the person's own devices: anyone else's is "not found", like one that never existed. */
    public function destroy(Request $request, string $deviceId): JsonResponse
    {
        $person = Person::of($request);

        Notifications::devicesOf($person)->whereKey($deviceId)->firstOrFail()->delete();
        event(new DeviceRemoved($person, $deviceId));

        return response()->json(null, 204);
    }
}
