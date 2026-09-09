<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support\Webauthn;

use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

/**
 * Lazily builds and caches the Symfony `Serializer` `Webauthn\Denormalizer\
 * WebauthnSerializerFactory` assembles — the one that knows how to turn a browser's
 * `navigator.credentials.create()`/`.get()` JSON response into `Webauthn\PublicKeyCredential` and
 * back. Building it does non-trivial reflection work (`PropertyInfoExtractor` +
 * `PhpDocExtractor`), so this is bound as a singleton (`App\Domain\Identity\
 * IdentityServiceProvider`) rather than reconstructed per ceremony.
 */
final class WebauthnSerializer
{
    private ?SerializerInterface $serializer = null;

    public function get(): SerializerInterface
    {
        return $this->serializer ??= (new WebauthnSerializerFactory(
            AttestationStatementSupportManager::create(),
        ))->create();
    }
}
