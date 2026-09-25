<?php

declare(strict_types=1);

use Kern\Platform\Logging\Redactions;
use Kern\Platform\Logging\SensitiveDataProcessor;
use Monolog\Level;
use Monolog\LogRecord;

/*
 * The shapes `kern/platform` recognises on its own — a password, a token, an IBAN, a JWT, an
 * `Authorization` header, an OTP. Built with an empty `Redactions`, which is the point: nothing here
 * needs an application to have declared anything.
 *
 * A CNP and a CUI are not here. They are Romanian, so they are declared by `kern/tax-romania` and
 * tested in that package's own suite — see `LogsNoRomanianIdentifierTest`.
 */

/**
 * @param array<array-key, mixed> $context
 * @param array<array-key, mixed> $extra
 */
function sensitiveDataTestRecord(string $message, array $context = [], array $extra = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable('2026-09-08T10:00:00Z'),
        channel: 'test',
        level: Level::Info,
        message: $message,
        context: $context,
        extra: $extra,
    );
}


test('an IBAN in the message is redacted', function (): void {
    $processor = new SensitiveDataProcessor(new Redactions());

    $result = $processor(sensitiveDataTestRecord('transfer to RO49AAAA1B31007593840000 failed'));

    expect($result->message)->not->toContain('RO49AAAA1B31007593840000')
        ->and($result->message)->toContain('[REDACTED]');
});

test('a password value in context is fully redacted regardless of key casing', function (): void {
    $processor = new SensitiveDataProcessor(new Redactions());

    $result = $processor(sensitiveDataTestRecord('login attempt', ['Password' => 'Sup3rSecret!']));

    expect($result->context['Password'])->toBe('[REDACTED]');
});

test('a token-shaped key is redacted under any common naming convention', function (): void {
    $processor = new SensitiveDataProcessor(new Redactions());

    $result = $processor(sensitiveDataTestRecord('api call', [
        'api_key' => 'abc123',
        'apiKey' => 'abc123',
        'API-KEY' => 'abc123',
        'access_token' => 'abc123',
    ]));

    foreach ($result->context as $value) {
        expect($value)->toBe('[REDACTED]');
    }
});

test('a JWT-shaped bearer token embedded in text is redacted', function (): void {
    $processor = new SensitiveDataProcessor(new Redactions());

    $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

    $result = $processor(sensitiveDataTestRecord("using token {$jwt}"));

    expect($result->message)->not->toContain($jwt)
        ->and($result->message)->toContain('[REDACTED]');
});

test('an Authorization header appearing in free text is redacted but the scheme is kept', function (): void {
    $processor = new SensitiveDataProcessor(new Redactions());

    $result = $processor(sensitiveDataTestRecord('Authorization: Bearer abcdef.ghijkl.mnopqr'));

    expect($result->message)->not->toContain('abcdef.ghijkl.mnopqr')
        ->and($result->message)->toContain('Authorization: Bearer [REDACTED]');
});

test('an OTP code inline in free text is redacted', function (): void {
    $processor = new SensitiveDataProcessor(new Redactions());

    $result = $processor(sensitiveDataTestRecord('SPV login: OTP: 482913 accepted'));

    expect($result->message)->not->toContain('482913')
        ->and($result->message)->toContain('[REDACTED]');
});

test('an OTP key in context is fully redacted', function (): void {
    $processor = new SensitiveDataProcessor(new Redactions());

    $result = $processor(sensitiveDataTestRecord('spv challenge', ['otp_code' => '482913']));

    expect($result->context['otp_code'])->toBe('[REDACTED]');
});

test('sensitive data nested arbitrarily deep in arrays is redacted', function (): void {
    $processor = new SensitiveDataProcessor(new Redactions());

    $result = $processor(sensitiveDataTestRecord('nested', [
        'request' => [
            'headers' => [
                'Authorization' => 'Bearer some.jwt.value',
            ],
            'body' => [
                'user' => [
                    'iban' => 'RO49AAAA1B31007593840000',
                ],
            ],
        ],
    ]));

    $encoded = json_encode($result->context);

    expect($encoded)->not->toContain('RO49AAAA1B31007593840000')
        ->and($encoded)->not->toContain('some.jwt.value')
        ->and($encoded)->toContain('[REDACTED]');
});

test('sensitive data nested inside a plain object is redacted', function (): void {
    $processor = new SensitiveDataProcessor(new Redactions());

    // What this proves is the *nesting*, so both values are shapes the platform knows by itself —
    // an identifier only `kern/tax-romania` can recognise is tested in that package's suite.
    $payload = new stdClass();
    $payload->password = 'Sup3rSecret!';
    $payload->iban = 'RO49AAAA1B31007593840000';

    $result = $processor(sensitiveDataTestRecord('object payload', ['payload' => $payload]));

    $encoded = json_encode($result->context);

    expect($encoded)->not->toContain('Sup3rSecret!')
        ->and($encoded)->not->toContain('RO49AAAA1B31007593840000');
});

test('a Throwable in context keeps its class and location but redacts its message', function (): void {
    $processor = new SensitiveDataProcessor(new Redactions());

    $exception = new RuntimeException('login failed for RO49AAAA1B31007593840000 password=Sup3rSecret!');

    $result = $processor(sensitiveDataTestRecord('spv error', ['exception' => $exception]));

    $encoded = json_encode($result->context);

    /** @var array{class: string, message: string, file: string, line: int} $exceptionContext */
    $exceptionContext = $result->context['exception'];

    expect($encoded)->not->toContain('RO49AAAA1B31007593840000')
        ->and($encoded)->not->toContain('Sup3rSecret!')
        ->and($exceptionContext['class'])->toBe(RuntimeException::class);
});

test('a circular object reference does not cause infinite recursion', function (): void {
    $processor = new SensitiveDataProcessor(new Redactions());

    $node = new stdClass();
    $node->self = $node;

    $result = $processor(sensitiveDataTestRecord('circular', ['node' => $node]));

    expect(json_encode($result->context))->toBeString();
});

test('non-sensitive data passes through unchanged', function (): void {
    $processor = new SensitiveDataProcessor(new Redactions());

    $result = $processor(sensitiveDataTestRecord('document processed', ['document_id' => 'doc-123', 'status' => 'READY']));

    expect($result->context)->toBe(['document_id' => 'doc-123', 'status' => 'READY']);
});
