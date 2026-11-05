<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Notifications;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Support\DeviceSummary;
use Kern\Platform\Notifications\Models\PushSubscription;
use Kern\Platform\Notifications\Rules\PushServiceAddress;

/**
 * `GET`, `POST` `v1/notifications/push/devices` and `DELETE …/{deviceId}` — the signed-in account's own
 * devices that receive notifications.
 *
 * A device is the browser's subscription: the push service's address and two keys, which only that
 * browser can use. They are never sent back; a device is shown by what a person recognises it by, and
 * by `endpoint_hash`, which lets the page tell "this device" among them without holding the address.
 */
final class PushDeviceController
{
    public function __construct(private readonly AuditChainService $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $devices = PushSubscription::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->get()
            ->map(static fn (PushSubscription $device): array => [
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
     * Turns notifications on for the browser that sent this. The same browser subscribing again — or
     * after another account signed in on it — is the same device, found by its address, and now
     * belongs to whoever is signed in: a phone notifies the person using it.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', new PushServiceAddress()],
            'keys.p256dh' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'keys.auth' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'locale' => ['required', 'string', 'regex:/^[a-z]{2}$/'],
        ]);

        $hash = PushSubscription::hashEndpoint($data['endpoint']);
        $existing = PushSubscription::query()->where('endpoint_hash', $hash)->first();

        if ($existing !== null && $existing->user_id !== $user->id) {
            // Encrypted under the previous account's key, so it cannot simply change hands.
            $existing->delete();
            $existing = null;
        }

        $limit = config()->integer('notifications.push.max_devices_per_user', 10);

        if ($existing === null && PushSubscription::query()->where('user_id', $user->id)->count() >= $limit) {
            throw ValidationException::withMessages([
                'endpoint' => [__('platform::notifications.push.too_many_devices', ['count' => $limit])],
            ]);
        }

        $device = DeviceSummary::read($request->userAgent());
        $attributes = [
            'user_id' => $user->id,
            'endpoint_hash' => $hash,
            'endpoint_ciphertext' => $data['endpoint'],
            'public_key_ciphertext' => $data['keys']['p256dh'],
            'auth_token_ciphertext' => $data['keys']['auth'],
            'locale' => $data['locale'],
            'device_kind' => $device->kind->value,
            'device_browser' => $device->browser,
            'device_platform' => $device->platform,
        ];

        if ($existing !== null) {
            $existing->fill($attributes)->save();

            return response()->json(['id' => $existing->id]);
        }

        $created = PushSubscription::query()->create($attributes);
        $this->record($user, 'PushDeviceAdded', $created->id);

        return response()->json(['id' => $created->id], 201);
    }

    /** Only the account's own devices: another account's is "not found", like one that never existed. */
    public function destroy(Request $request, string $deviceId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $device = PushSubscription::query()->where('user_id', $user->id)->whereKey($deviceId)->firstOrFail();
        $device->delete();
        $this->record($user, 'PushDeviceRemoved', $deviceId);

        return response()->json(null, 204);
    }

    private function record(User $user, string $event, string $deviceId): void
    {
        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $user->id,
            eventType: $event,
            resourceType: 'PushSubscription',
            resourceId: $deviceId,
            payloadHash: $this->audit->hashPayload([]),
        ));
    }
}
