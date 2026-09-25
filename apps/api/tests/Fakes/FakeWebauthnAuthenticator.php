<?php

declare(strict_types=1);

namespace Tests\Fakes;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * A real ECDSA P-256 ("ES256") key pair standing in for a physical authenticator, used to build
 * genuine, cryptographically valid WebAuthn registration/assertion responses for feature tests —
 * not a mock of `Webauthn\AuthenticatorAttestationResponseValidator`/
 * `AuthenticatorAssertionResponseValidator`, which run for real against this class's output.
 *
 * Every byte layout here follows the WebAuthn/CBOR spec exactly as `web-auth/webauthn-lib` itself
 * parses it (`Webauthn\AuthenticatorDataLoader::load()`, `Webauthn\CeremonyStep\CheckSignature`) —
 * confirmed by reading that source directly before writing this class, not assumed from the spec
 * text alone. Attestation format is always `none` (`fmt: "none", attStmt: {}`), matching
 * `Kern\Platform\Identity\Support\Webauthn\PasskeyManager`'s only registered
 * `AttestationStatementSupport`.
 */
final class FakeWebauthnAuthenticator
{
    private readonly OpenSSLAsymmetricKey $privateKey;

    private readonly string $x;

    private readonly string $y;

    public readonly string $credentialId;

    public function __construct(?string $credentialId = null)
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            throw new RuntimeException('Could not generate a fake authenticator key pair.');
        }

        $this->privateKey = $key;

        $details = openssl_pkey_get_details($key);

        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('Could not read the fake authenticator public key.');
        }

        $this->x = str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $this->y = str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $this->credentialId = $credentialId ?? random_bytes(32);
    }

    /**
     * Builds a full `navigator.credentials.create()`-shaped JSON response (as a PHP array, ready
     * for `json_decode`-free use) satisfying `$options`.
     *
     * @return array{id: string, rawId: string, type: string, response: array{clientDataJSON: string, attestationObject: string, transports: list<string>}}
     */
    public function register(PublicKeyCredentialCreationOptions $options, string $origin): array
    {
        $clientDataJson = $this->clientDataJson('webauthn.create', $options->challenge, $origin);
        $rpIdHash = hash('sha256', (string) $options->rp->id, true);

        $flags = chr(0x01 | 0x04 | 0x40); // UP | UV | AT
        $authData = $rpIdHash
            . $flags
            . pack('N', 0)
            . str_repeat("\0", 16) // aaguid — zeroed, this fake authenticator has none
            . pack('n', strlen($this->credentialId))
            . $this->credentialId
            . $this->coseKeyBytes();

        $attestationObject = (string) MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return [
            'id' => $this->base64Url($this->credentialId),
            'rawId' => $this->base64Url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->base64Url($clientDataJson),
                'attestationObject' => $this->base64Url($attestationObject),
                'transports' => ['internal'],
            ],
        ];
    }

    /**
     * Builds a full `navigator.credentials.get()`-shaped JSON response.
     *
     * @return array{id: string, rawId: string, type: string, response: array{clientDataJSON: string, authenticatorData: string, signature: string, userHandle: string}}
     */
    public function assert(PublicKeyCredentialRequestOptions $options, string $origin, string $userHandle, int $signCount = 1): array
    {
        $clientDataJson = $this->clientDataJson('webauthn.get', $options->challenge, $origin);
        $rpIdHash = hash('sha256', (string) $options->rpId, true);

        $flags = chr(0x01 | 0x04); // UP | UV, no attested credential data on assertion
        $authData = $rpIdHash . $flags . pack('N', $signCount);

        $clientDataHash = hash('sha256', $clientDataJson, true);
        $signature = $this->sign($authData . $clientDataHash);

        return [
            'id' => $this->base64Url($this->credentialId),
            'rawId' => $this->base64Url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->base64Url($clientDataJson),
                'authenticatorData' => $this->base64Url($authData),
                'signature' => $this->base64Url($signature),
                'userHandle' => $this->base64Url($userHandle),
            ],
        ];
    }

    private function coseKeyBytes(): string
    {
        return (string) MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2)) // kty: EC2
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7)) // alg: ES256
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1)) // crv: P-256
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($this->x))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($this->y));
    }

    private function sign(string $data): string
    {
        $signature = '';

        if (! openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign fake authenticator data.');
        }

        return $signature;
    }

    private function clientDataJson(string $type, string $challenge, string $origin): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => $this->base64Url($challenge),
            'origin' => $origin,
        ], JSON_THROW_ON_ERROR);
    }

    private function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
