<?php

declare(strict_types=1);

namespace Kern\Platform\Http;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * What an HTTP client checks a service's certificate against.
 *
 * Every service inside the stack has a certificate from the stack's own CA (`docker/certs`), and a
 * service elsewhere may have one from a private CA of its own; either way the CA's file is named in
 * config, and a client verifies against that file and nothing else. With no file named, the system's
 * CAs — the right answer for a service with a certificate any browser would trust.
 *
 * There is no answer that switches verification off. A client that does not check the certificate
 * encrypts the connection to whoever answers it, which is no protection at all against the one thing
 * TLS inside the stack is for.
 */
final class CertificateAuthority
{
    /**
     * Guzzle's `verify` option for the CA file named at `$key`: its path, or `true` for the system's.
     */
    public static function verifyOption(ConfigRepository $config, string $key): string|true
    {
        $file = $config->get($key);

        return is_string($file) && $file !== '' ? $file : true;
    }
}
