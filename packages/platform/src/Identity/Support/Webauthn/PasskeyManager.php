<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Support\Webauthn;

use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use JsonException;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\Normalizer;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserPasskey;
use Kern\Platform\Identity\Support\Webauthn\Exceptions\PasskeyVerificationException;
use RuntimeException;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * The single entry point every controller uses for passkey registration and login. Orchestrates
 * `web-auth/webauthn-lib` (ceremony-step validation) against this project's own storage
 * (`WebauthnCredentialRepository`) and in-flight-challenge tracking (`ChallengeStore`).
 *
 * Registered `AttestationConveyancePreference::NONE` and only `ES256`/`RS256` signature
 * algorithms — the two virtually every platform authenticator (Touch ID, Windows Hello, Android)
 * and every FIDO2 security key supports; adding more is an additive change to
 * `algorithmParameters()`, not a redesign.
 */
final readonly class PasskeyManager
{
    public function __construct(
        private WebauthnSerializer $serializer,
        private ChallengeStore $challenges,
        private WebauthnCredentialRepository $credentials,
        private BlindIndexHasher $blindIndex,
    ) {
    }

    /**
     * @return array<string, mixed> JSON-ready options for `navigator.credentials.create()`
     */
    public function creationOptionsFor(User $user): array
    {
        $options = PublicKeyCredentialCreationOptions::create(
            $this->relyingParty(),
            PublicKeyCredentialUserEntity::create(
                $this->accountLabel($user),
                $user->id,
                $this->accountLabel($user),
            ),
            random_bytes(32),
            $this->algorithmParameters(),
            AuthenticatorSelectionCriteria::create(userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $this->credentials->credentialDescriptorsFor($user->id),
            $this->timeoutMs(),
        );

        $this->challenges->putCreationOptions($user->id, $options);

        return $this->toJsonArray($options);
    }

    /**
     * @param array<string, mixed> $credentialJson the browser's `navigator.credentials.create()` response, decoded from JSON
     */
    public function verifyRegistration(User $user, array $credentialJson, string $host, ?string $name): UserPasskey
    {
        $options = $this->challenges->pullCreationOptions($user->id);
        $publicKeyCredential = $this->deserializeCredential($credentialJson);

        if (! $publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
            throw PasskeyVerificationException::fromLibraryFailure(new JsonException('not an attestation response'));
        }

        try {
            $record = AuthenticatorAttestationResponseValidator::create($this->ceremonyFactory()->creationCeremony())
                ->check($publicKeyCredential->response, $options, $host);
        } catch (Throwable $exception) {
            throw PasskeyVerificationException::fromLibraryFailure($exception);
        }

        return $this->credentials->store($record, $user->id, $name);
    }

    /**
     * Login is by email first (this module never uses fully discoverable/resident-key passkey
     * login), so there is no authenticated user yet at this point — the ceremony is keyed by the
     * email's blind index (`ChallengeStore`'s own docblock explains why that is safe to use as a
     * cache key). Deliberately returns valid-shaped options whether or not the email is known or
     * has any registered passkey (an empty `allowCredentials` list otherwise), so this endpoint's
     * response cannot itself be used to enumerate accounts — see `docs/handoff/017-authentication.md`
     * "User-enumeration resistance" for the matching treatment on the password login path.
     *
     * @return array<string, mixed> JSON-ready options for `navigator.credentials.get()`
     */
    public function requestOptionsForEmail(string $email): array
    {
        $key = $this->emailKey($email);
        $user = User::query()->whereEmail($email)->first();

        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->relyingParty()->id,
            $user !== null ? $this->credentials->credentialDescriptorsFor($user->id) : [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            $this->timeoutMs(),
        );

        $this->challenges->putRequestOptions($key, $options);

        return $this->toJsonArray($options);
    }

    /**
     * @param array<string, mixed> $credentialJson the browser's `navigator.credentials.get()` response, decoded from JSON
     */
    public function verifyAssertionForEmail(string $email, array $credentialJson, string $host): User
    {
        $options = $this->challenges->pullRequestOptions($this->emailKey($email));
        $publicKeyCredential = $this->deserializeCredential($credentialJson);

        if (! $publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
            throw PasskeyVerificationException::fromLibraryFailure(new JsonException('not an assertion response'));
        }

        $user = User::query()->whereEmail($email)->first();
        $passkey = $this->credentials->findByCredentialId($publicKeyCredential->rawId);

        if ($user === null || $passkey === null || $passkey->user_id !== $user->id) {
            throw PasskeyVerificationException::unknownCredential();
        }

        try {
            $record = AuthenticatorAssertionResponseValidator::create($this->ceremonyFactory()->requestCeremony())
                ->check(
                    $this->credentials->toCredentialRecord($passkey),
                    $publicKeyCredential->response,
                    $options,
                    $host,
                    $user->id,
                );
        } catch (Throwable $exception) {
            throw PasskeyVerificationException::fromLibraryFailure($exception);
        }

        $this->credentials->updateAfterAssertion($passkey, $record);

        return $user;
    }

    private function emailKey(string $email): string
    {
        return $this->blindIndex->compute(Normalizer::email($email));
    }

    /**
     * @param array<string, mixed> $credentialJson
     */
    private function deserializeCredential(array $credentialJson): PublicKeyCredential
    {
        try {
            $json = json_encode($credentialJson, JSON_THROW_ON_ERROR);

            $credential = $this->serializer->get()->deserialize($json, PublicKeyCredential::class, 'json');
        } catch (Throwable $exception) {
            throw PasskeyVerificationException::fromLibraryFailure($exception);
        }

        // The serializer's static return type is inferred from the literal class-name argument
        // above, so PHPStan considers this check always true — kept anyway: $credentialJson is
        // untrusted browser input (docs/AGENTS.md §8's "documents/input are untrusted data"
        // principle applies equally to a WebAuthn client response), and a defensive runtime check
        // against a static-analysis assumption is exactly the belt-and-suspenders trade this
        // project's threat model (BLUEPRINT.md §4.1) asks for.
        // @phpstan-ignore instanceof.alwaysTrue
        if (! $credential instanceof PublicKeyCredential) {
            throw PasskeyVerificationException::fromLibraryFailure(new JsonException('unexpected payload shape'));
        }

        return $credential;
    }

    /**
     * @return array<string, mixed>
     */
    private function toJsonArray(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): array
    {
        $json = $this->serializer->get()->serialize($options, 'json');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Who this application says it is, to a browser about to bind a key to it.
     *
     * Both values are required and neither has a fallback, because a passkey is bound to the relying
     * party it was created under and cannot be moved afterwards. A name defaulting to the empty
     * string shows the person a nameless prompt; an id defaulting to `localhost` — which is what an
     * unset `APP_URL` used to produce — binds the credential to a host this application is not
     * served from, and the person cannot undo it. Refusing outright is the only honest answer.
     */
    private function relyingParty(): PublicKeyCredentialRpEntity
    {
        $name = config('webauthn.relying_party.name');
        $id = config('webauthn.relying_party.id');

        if (! is_string($name) || $name === '') {
            throw new RuntimeException('webauthn.relying_party.name is empty — set APP_NAME.');
        }

        if (! is_string($id) || $id === '') {
            throw new RuntimeException(
                'webauthn.relying_party.id is empty — set APP_URL to the address this application is '
                . 'served from, or WEBAUTHN_RP_ID to the host a passkey should be bound to.'
            );
        }

        return PublicKeyCredentialRpEntity::create($name, $id);
    }

    /**
     * @return list<PublicKeyCredentialParameters>
     */
    private function algorithmParameters(): array
    {
        return [
            PublicKeyCredentialParameters::createPk(ES256::ID),
            PublicKeyCredentialParameters::createPk(RS256::ID),
        ];
    }

    private function ceremonyFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();

        /** @var list<string> $origins */
        $origins = config('webauthn.allowed_origins', []);

        $factory->setAllowedOrigins($origins);

        return $factory;
    }

    /**
     * @return positive-int
     */
    private function timeoutMs(): int
    {
        $timeout = config('webauthn.timeout_ms');
        $value = is_int($timeout) ? $timeout : 60000;

        return $value > 0 ? $value : 60000;
    }

    private function accountLabel(User $user): string
    {
        $email = $user->email_ciphertext;

        return is_string($email) && $email !== '' ? $email : $user->id;
    }
}
