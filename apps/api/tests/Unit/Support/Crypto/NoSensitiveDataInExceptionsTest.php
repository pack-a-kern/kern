<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Crypto\NoSensitiveDataInExceptionsTest;

use App\Support\Crypto\AssociatedData;
use App\Support\Crypto\EnvelopeCipher;
use App\Support\Crypto\Exceptions\CastConfigurationException;
use App\Support\Crypto\Exceptions\EnvelopeException;
use App\Support\KeyManagement\Exceptions\KeyManagementException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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

it('no file under Support/Crypto or Support/KeyManagement calls a logging function', function (): void {
    $roots = [
        dirname(__DIR__, 4) . '/app/Support/Crypto',
        dirname(__DIR__, 4) . '/app/Support/KeyManagement',
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
