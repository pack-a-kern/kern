<?php

declare(strict_types=1);

namespace Kern\Notifications\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use JsonException;
use Kern\Notifications\DataTransferObjects\Message;
use Kern\Notifications\Models\Device;
use Kern\Notifications\Notifications;
use Kern\Notifications\Rules\PushServiceAddress;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Throwable;

/**
 * Sends one message to every device of one person, each in its own language.
 *
 * The encryption to the device (RFC 8291) and the server's signature (VAPID, RFC 8292) are
 * `minishlink/web-push`'s — never written here. A device the push service says is gone (404, 410) is
 * removed, so it is not sent to again; any other refusal is logged by its status code only, since the
 * address itself is the device's secret.
 */
final readonly class PushSender
{
    /** @param ClientInterface|null $client the HTTP client to the push services; discovered when not given */
    public function __construct(private ?ClientInterface $client = null)
    {
    }

    public function configured(): bool
    {
        return $this->publicKey() !== '' && $this->privateKey() !== '';
    }

    public function publicKey(): string
    {
        return config()->string('notifications.push.vapid.public_key', '');
    }

    /**
     * @param  Closure(string): ?Message  $message  what to say, given a device's language; null says nothing
     * @return int how many devices accepted it
     */
    public function send(Model $notifiable, Closure $message): int
    {
        if (! $this->configured()) {
            return 0;
        }

        $devices = Notifications::devicesOf($notifiable)->get();

        if ($devices->isEmpty()) {
            return 0;
        }

        $push = $this->client();
        /** @var array<string, Device> $byEndpoint */
        $byEndpoint = [];

        foreach ($devices as $device) {
            $endpoint = $device->endpoint;
            $said = $message($device->locale);

            // Checked again on the way out: a row written before the list of hosts changed, or by
            // anything other than the endpoint that validates them, is still never sent to.
            if ($said === null || ! PushServiceAddress::allows($endpoint)) {
                continue;
            }

            try {
                $payload = json_encode($said->toArray(), JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            $byEndpoint[$endpoint] = $device;
            $push->queueNotification(
                Subscription::create([
                    'endpoint' => $endpoint,
                    'publicKey' => $device->public_key,
                    'authToken' => $device->auth_token,
                    'contentEncoding' => 'aes128gcm',
                ]),
                $payload,
            );
        }

        $accepted = 0;

        try {
            /** @var MessageSentReport $report */
            foreach ($push->flush() as $report) {
                $device = $byEndpoint[$report->getEndpoint()] ?? null;

                if ($device === null) {
                    continue;
                }

                if ($report->isSuccess()) {
                    $device->forceFill(['last_used_at' => now()])->save();
                    $accepted++;
                } elseif ($report->isSubscriptionExpired()) {
                    $device->delete();
                } else {
                    Log::warning('push_send_refused', ['status' => $report->getResponse()?->getStatusCode()]);
                }
            }
        } catch (Throwable $exception) {
            // A push service that does not answer must not fail what sent the notice — the bell
            // already has it. Logged by class only: a message could quote the address.
            Log::warning('push_send_failed', ['exception' => $exception::class]);
        }

        return $accepted;
    }

    private function client(): WebPush
    {
        $subject = config()->string('notifications.push.vapid.subject', '');

        return new WebPush(
            [
                'VAPID' => [
                    'subject' => $subject !== '' ? $subject : config()->string('app.url'),
                    'publicKey' => $this->publicKey(),
                    'privateKey' => $this->privateKey(),
                ],
            ],
            ['TTL' => config()->integer('notifications.push.ttl_seconds', 86400)],
            $this->client,
        );
    }

    private function privateKey(): string
    {
        return config()->string('notifications.push.vapid.private_key', '');
    }
}
