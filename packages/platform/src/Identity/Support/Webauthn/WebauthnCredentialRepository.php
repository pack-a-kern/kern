<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Support\Webauthn;

use Kern\Platform\Identity\Models\UserPasskey;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Converts between `Kern\Platform\Identity\Models\UserPasskey` (this project's own storage) and
 * `Webauthn\CredentialRecord` (the library's in-memory representation) — the library's 5.x API
 * has no repository interface of its own to implement (see `docs/handoff/017-authentication.md`
 * "Key decisions" for why: the validators take an already-looked-up `CredentialRecord` rather
 * than a credential id, so *this* class, not the library, owns "how do we find one".
 *
 * `credential_id`/`public_key` are stored base64-encoded (`user_passkeys` migration's docblock
 * explains why plaintext, not `Casts\Encrypted`) because raw WebAuthn binary strings are not valid
 * UTF-8 and cannot be written to a Postgres `text` column directly.
 *
 * Attestation is always `EmptyTrustPath` (`none` format, the only one this module's
 * `CeremonyManagers` registers — see that class) — irrelevant to assertion verification, only
 * needed to satisfy `CredentialRecord`'s constructor.
 */
final class WebauthnCredentialRepository
{
    public function findByCredentialId(string $rawCredentialId): ?UserPasskey
    {
        return UserPasskey::query()
            ->where('credential_id', base64_encode($rawCredentialId))
            ->first();
    }

    public function toCredentialRecord(UserPasskey $passkey): CredentialRecord
    {
        /** @var list<string> $transports */
        $transports = $passkey->transports;

        return CredentialRecord::create(
            $this->decodeBase64($passkey->credential_id),
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            $transports,
            $passkey->attestation_type,
            EmptyTrustPath::create(),
            Uuid::fromString($passkey->aaguid),
            $this->decodeBase64($passkey->public_key),
            $passkey->user_id,
            $passkey->sign_count,
            null,
            $passkey->backup_eligible,
            $passkey->backup_status,
            $passkey->uv_initialized,
        );
    }

    /**
     * Every passkey a user has registered, as `PublicKeyCredentialDescriptor`s — used both as
     * `excludeCredentials` on a new registration (so the same authenticator cannot be enrolled
     * twice) and as `allowCredentials` on a login assertion request (this module always identifies
     * the user by email first, so the assertion is scoped to that user's own credentials only,
     * never a fully discoverable/resident-key flow).
     *
     * @return list<PublicKeyCredentialDescriptor>
     */
    public function credentialDescriptorsFor(string $userId): array
    {
        $descriptors = [];

        foreach (UserPasskey::query()->where('user_id', $userId)->get() as $passkey) {
            $descriptors[] = PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                $this->decodeBase64($passkey->credential_id),
                $passkey->transports,
            );
        }

        return $descriptors;
    }

    private function decodeBase64(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            throw new RuntimeException('Stored WebAuthn value is not valid base64.');
        }

        return $decoded;
    }

    public function store(CredentialRecord $record, string $userId, ?string $name): UserPasskey
    {
        return UserPasskey::create([
            'user_id' => $userId,
            'credential_id' => base64_encode($record->publicKeyCredentialId),
            'public_key' => base64_encode($record->credentialPublicKey),
            'aaguid' => $record->aaguid->toRfc4122(),
            'attestation_type' => $record->attestationType,
            'transports' => $record->transports,
            'sign_count' => $record->counter,
            'backup_eligible' => $record->backupEligible,
            'backup_status' => $record->backupStatus,
            'uv_initialized' => $record->uvInitialized,
            'name' => $name,
        ]);
    }

    public function updateAfterAssertion(UserPasskey $passkey, CredentialRecord $record): void
    {
        $passkey->sign_count = $record->counter;
        $passkey->backup_eligible = $record->backupEligible;
        $passkey->backup_status = $record->backupStatus;
        $passkey->uv_initialized = $record->uvInitialized;
        $passkey->last_used_at = now('UTC');
        $passkey->save();
    }
}
