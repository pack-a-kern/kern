<?php

declare(strict_types=1);

namespace Kern\Platform\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Crypto\Casts\Encrypted;
use Kern\Platform\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Crypto\Contracts\EncryptsWithSubjectDek;
use RuntimeException;

/**
 * One device that receives this account's notifications (`push_subscriptions` — see its migration).
 *
 * The push service's address and the two keys are encrypted with the account's own key: they are what
 * reach that one browser, and a copy of the table must not be a way to send to it.
 *
 * @property string $id
 * @property string $user_id
 * @property string $endpoint_hash
 * @property string $endpoint_ciphertext
 * @property string $public_key_ciphertext
 * @property string $auth_token_ciphertext
 * @property string $locale
 * @property string $device_kind
 * @property string|null $device_browser
 * @property string|null $device_platform
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class PushSubscription extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'endpoint_hash',
        'endpoint_ciphertext',
        'public_key_ciphertext',
        'auth_token_ciphertext',
        'locale',
        'device_kind',
        'device_browser',
        'device_platform',
        'last_used_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'endpoint_ciphertext' => Encrypted::class,
        'public_key_ciphertext' => Encrypted::class,
        'auth_token_ciphertext' => Encrypted::class,
        'last_used_at' => 'datetime',
    ];

    public function encryptionSubjectId(): string
    {
        $value = $this->getAttribute('user_id');

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('PushSubscription.user_id must be a non-empty string.');
        }

        return $value;
    }

    /** The one way to name a push address without keeping it in the clear. */
    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
