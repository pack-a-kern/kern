<?php

declare(strict_types=1);

namespace Kern\Notifications\Testing;

use RuntimeException;

/**
 * What a browser's `PushManager.subscribe()` hands the page, for tests: a real P-256 public key and a
 * random auth secret, so a message can actually be encrypted to it.
 */
final class BrowserSubscription
{
    /** @return array{endpoint: string, keys: array{p256dh: string, auth: string}, locale: string} */
    public static function make(string $endpoint = 'https://fcm.googleapis.com/fcm/send/device-1', string $locale = 'en'): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if (! is_array($details)) {
            throw new RuntimeException('OpenSSL could not make a P-256 key.');
        }

        $point = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => self::base64Url($point), 'auth' => self::base64Url(random_bytes(16))],
            'locale' => $locale,
        ];
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
