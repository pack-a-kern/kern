<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Crypto\NoSensitiveDataInExceptionsTest;

use Kern\Platform\Crypto\AssociatedData;
use Kern\Platform\Crypto\EnvelopeCipher;
use Kern\Platform\Crypto\Exceptions\CastConfigurationException;
use Kern\Platform\Crypto\Exceptions\EnvelopeException;
use Kern\Platform\KeyManagement\Exceptions\KeyManagementException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

const SECRET_PLAINTEXT = 'super-secret-cnp-1900101234567';

it('does not leak the plaintext when an envelope fails authentication', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $aad = AssociatedData::for('subject-1', 'pf_profiles', 'cnp', 'record-1');
    $envelope = EnvelopeCipher::seal(SECRET_PLAINTEXT, $key, $aad);

    $exception = null;

    try {
        EnvelopeCipher::open($envelope, $key, AssociatedData::for('subject-2', 'pf_profiles', 'cnp', 'record-1'));
    } catch (EnvelopeException $caught) {
        $exception = $caught;
    }

    expect($exception)->not->toBeNull();
    /** @var EnvelopeException $exception */
    expect($exception->getMessage())
        ->not->toContain(SECRET_PLAINTEXT)
        ->not->toContain(base64_encode($key))
        ->not->toContain($envelope);
});

it('does not leak the KMS token in any KeyManagementException factory message', function (): void {
    $secretToken = 'openbao-root-token-should-never-appear';

    $messages = [
        KeyManagementException::requestFailed('encrypt', 403)->getMessage(),
        KeyManagementException::unreachable('encrypt')->getMessage(),
        KeyManagementException::malformedResponse('decrypt')->getMessage(),
        KeyManagementException::malformedCiphertext('decrypt')->getMessage(),
    ];

    foreach ($messages as $message) {
        expect($message)->not->toContain($secretToken);
    }
});

it('CastConfigurationException messages never include a field value, only class names', function (): void {
    $message = CastConfigurationException::missingPrimaryKey('App\\Some\\Model')->getMessage();

    expect($message)->not->toContain(SECRET_PLAINTEXT);
});

it('no file in the platform\'s crypto or key management calls a logging function', function (): void {
    // Located through the classes themselves rather than a path: this code is `kern/platform` now,
    // and a guard that hardcoded where a package is installed would pass by scanning nothing.
    $roots = [
        dirname((string) (new ReflectionClass(EnvelopeCipher::class))->getFileName()),
        dirname((string) (new ReflectionClass(KeyManagementException::class))->getFileName(), 2),
    ];

    $offenders = [];

    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if ($contents === false) {
                continue;
            }

            if (preg_match('/\b(Log::|logger\(|error_log\(|var_dump\(|dd\(|dump\()/', $contents) === 1) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBe([]);
});
