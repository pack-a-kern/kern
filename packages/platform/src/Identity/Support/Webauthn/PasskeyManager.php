<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Support\Webauthn;

use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use JsonException;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserPasskey;
use Kern\Platform\Identity\Support\Webauthn\Exceptions\PasskeyVerificationException;
use RuntimeException;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
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
            // Verification required, not preferred: a passkey stands in for the password *and* the
            // second factor, so the device must have checked it is its owner — a fingerprint, a face,
            // a PIN — and not merely that it is present. A discoverable key, required: login names no
            // account (`requestOptions()`), so a key the device does not keep could never be offered
            // there — an authenticator that cannot keep one is refused here, where it can be said,
            // rather than failing at every login after.
            AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
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
     * Login names no account: the options carry no `allowCredentials`, so the browser offers every
     * passkey it keeps for this site, and the one chosen says whose it is (`verifyAssertion()`).
     * Nothing about any account is in the answer, so it cannot be used to learn which exist.
     *
     * `$ceremonyKey` binds the challenge to the browser that asked for it — the caller derives it from
     * that browser's session (`PasskeyLoginController`). One pending challenge per session: asking
     * again replaces it, so a client cannot pile challenges up in the cache.
     *
     * @return array<string, mixed> JSON-ready options for `navigator.credentials.get()`
     */
    public function requestOptions(string $ceremonyKey): array
    {
        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->relyingParty()->id,
            [],
            // Required for the same reason as at registration — see `creationOptionsFor()`.
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            $this->timeoutMs(),
        );

        $this->challenges->putRequestOptions($ceremonyKey, $options);

        return $this->toJsonArray($options);
    }

    /**
     * The account a passkey assertion proves, or an exception that says nothing about why.
     *
     * The passkey is found by its credential id, and the account is the one that registered it —
     * never one the client names. The `userHandle` the device sends must be that same account: a
     * discoverable credential always carries it, and a mismatch means a credential presented for an
     * account that is not its own. Then the library checks the signature against the stored public
     * key, the challenge this session was given (one use, `ChallengeStore::pull*`), the origin, user
     * verification, and the signature counter (a cloned key shows as a counter that went backwards).
     *
     * Every refusal — unknown credential, foreign handle, bad signature — is the same exception, so a
     * caller learns nothing it could probe with.
     *
     * @param array<string, mixed> $credentialJson the browser's `navigator.credentials.get()` response, decoded from JSON
     */
    public function verifyAssertion(string $ceremonyKey, array $credentialJson, string $host): User
    {
        $options = $this->challenges->pullRequestOptions($ceremonyKey);
        $publicKeyCredential = $this->deserializeCredential($credentialJson);
        $response = $publicKeyCredential->response;

        if (! $response instanceof AuthenticatorAssertionResponse) {
            throw PasskeyVerificationException::fromLibraryFailure(new JsonException('not an assertion response'));
        }

        $passkey = $this->credentials->findByCredentialId($publicKeyCredential->rawId);
        $user = $passkey !== null ? User::query()->find($passkey->user_id) : null;

        if ($passkey === null || ! $user instanceof User || $response->userHandle === null
            || ! hash_equals($user->id, $response->userHandle)) {
            throw PasskeyVerificationException::unknownCredential();
        }

        try {
            $record = AuthenticatorAssertionResponseValidator::create($this->ceremonyFactory()->requestCeremony())
                ->check(
                    $this->credentials->toCredentialRecord($passkey),
                    $response,
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
        // Without the absent members, not with them set to null: a browser reads `residentKey: null`
        // as an unknown value and ignores it, and says so in the console on every ceremony.
        $json = $this->serializer->get()->serialize($options, 'json', [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);

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
