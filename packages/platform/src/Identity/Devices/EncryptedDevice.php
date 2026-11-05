<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Devices;

use Kern\Notifications\Models\Device;
use Kern\Platform\Crypto\Casts\Encrypted;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Crypto\Contracts\EncryptsWithSubjectDek;
use RuntimeException;

/**
 * A device that receives a user's notices, with its push address and keys sealed under that user's
 * own key — as the TOTP secret is — rather than the application key the package would use.
 *
 * So an erased user's devices cannot be read back even from a copy of the table, and the ciphertext is
 * bound to its row: moved to another, it no longer opens. The id is set when the model is made, since
 * that binding needs it before the first attribute is sealed.
 */
final class EncryptedDevice extends Device implements EncryptsWithSubjectDek
{
    use HasUuidPrimaryKey;

    public function encryptionSubjectId(): string
    {
        $owner = $this->getAttribute('notifiable_id');

        if (! is_string($owner) || $owner === '') {
            throw new RuntimeException('A device is sealed for its owner, and this one has none yet.');
        }

        return $owner;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'endpoint' => Encrypted::class,
            'public_key' => Encrypted::class,
            'auth_token' => Encrypted::class,
            'last_used_at' => 'datetime',
        ];
    }
}
