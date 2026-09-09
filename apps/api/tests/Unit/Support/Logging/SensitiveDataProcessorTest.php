<?php

declare(strict_types=1);

use App\Support\Logging\SensitiveDataProcessor;
use Monolog\Level;
use Monolog\LogRecord;

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

test('a CNP-shaped number in the message is redacted', function (): void {
    $processor = new SensitiveDataProcessor();

    $result = $processor(sensitiveDataTestRecord('subject cnp is 1900101221144'));

    expect($result->message)->not->toContain('1900101221144')
        ->and($result->message)->toContain('[REDACTED]');
});

test('an IBAN in the message is redacted', function (): void {
    $processor = new SensitiveDataProcessor();

    $result = $processor(sensitiveDataTestRecord('transfer to RO49AAAA1B31007593840000 failed'));

    expect($result->message)->not->toContain('RO49AAAA1B31007593840000')
        ->and($result->message)->toContain('[REDACTED]');
});

test('a password value in context is fully redacted regardless of key casing', function (): void {
    $processor = new SensitiveDataProcessor();

    $result = $processor(sensitiveDataTestRecord('login attempt', ['Password' => 'Sup3rSecret!']));

    expect($result->context['Password'])->toBe('[REDACTED]');
});

test('a token-shaped key is redacted under any common naming convention', function (): void {
    $processor = new SensitiveDataProcessor();

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
    $processor = new SensitiveDataProcessor();

    $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

    $result = $processor(sensitiveDataTestRecord("using token {$jwt}"));

    expect($result->message)->not->toContain($jwt)
        ->and($result->message)->toContain('[REDACTED]');
});

test('an Authorization header appearing in free text is redacted but the scheme is kept', function (): void {
    $processor = new SensitiveDataProcessor();

    $result = $processor(sensitiveDataTestRecord('Authorization: Bearer abcdef.ghijkl.mnopqr'));

    expect($result->message)->not->toContain('abcdef.ghijkl.mnopqr')
        ->and($result->message)->toContain('Authorization: Bearer [REDACTED]');
});

test('an OTP code inline in free text is redacted', function (): void {
    $processor = new SensitiveDataProcessor();

    $result = $processor(sensitiveDataTestRecord('SPV login: OTP: 482913 accepted'));

    expect($result->message)->not->toContain('482913')
        ->and($result->message)->toContain('[REDACTED]');
});

test('an OTP key in context is fully redacted', function (): void {
    $processor = new SensitiveDataProcessor();

    $result = $processor(sensitiveDataTestRecord('spv challenge', ['otp_code' => '482913']));

    expect($result->context['otp_code'])->toBe('[REDACTED]');
});

test('sensitive data nested arbitrarily deep in arrays is redacted', function (): void {
    $processor = new SensitiveDataProcessor();

    $result = $processor(sensitiveDataTestRecord('nested', [
        'request' => [
            'headers' => [
                'Authorization' => 'Bearer some.jwt.value',
            ],
            'body' => [
                'user' => [
                    'cnp' => '1900101221144',
                    'iban' => 'RO49AAAA1B31007593840000',
                ],
            ],
        ],
    ]));

    $encoded = json_encode($result->context);

    expect($encoded)->not->toContain('1900101221144')
        ->and($encoded)->not->toContain('RO49AAAA1B31007593840000')
        ->and($encoded)->not->toContain('some.jwt.value')
        ->and($encoded)->toContain('[REDACTED]');
});

test('sensitive data nested inside a plain object is redacted', function (): void {
    $processor = new SensitiveDataProcessor();

    $payload = new stdClass();
    $payload->password = 'Sup3rSecret!';
    $payload->cnp = '1900101221144';

    $result = $processor(sensitiveDataTestRecord('object payload', ['payload' => $payload]));

    $encoded = json_encode($result->context);

    expect($encoded)->not->toContain('Sup3rSecret!')
        ->and($encoded)->not->toContain('1900101221144');
});

test('a Throwable in context keeps its class and location but redacts its message', function (): void {
    $processor = new SensitiveDataProcessor();

    $exception = new RuntimeException('SPV login failed for cnp 1900101221144 password=Sup3rSecret!');

    $result = $processor(sensitiveDataTestRecord('spv error', ['exception' => $exception]));

    $encoded = json_encode($result->context);

    /** @var array{class: string, message: string, file: string, line: int} $exceptionContext */
    $exceptionContext = $result->context['exception'];

    expect($encoded)->not->toContain('1900101221144')
        ->and($encoded)->not->toContain('Sup3rSecret!')
        ->and($exceptionContext['class'])->toBe(RuntimeException::class);
});

test('a circular object reference does not cause infinite recursion', function (): void {
    $processor = new SensitiveDataProcessor();

    $node = new stdClass();
    $node->self = $node;

    $result = $processor(sensitiveDataTestRecord('circular', ['node' => $node]));

    expect(json_encode($result->context))->toBeString();
});

test('non-sensitive data passes through unchanged', function (): void {
    $processor = new SensitiveDataProcessor();

    $result = $processor(sensitiveDataTestRecord('document processed', ['document_id' => 'doc-123', 'status' => 'READY']));

    expect($result->context)->toBe(['document_id' => 'doc-123', 'status' => 'READY']);
});

test('a business fiscal identifier is redacted for non-PF subjects', function (): void {
    $processor = new SensitiveDataProcessor();

    // A PFA's fiscal identifier is a CUI, not a CNP. Redacting only `cnp` would have leaked the
    // equivalent identifier for every non-PF subject type once M12 introduced PFA.
    // See docs/reference/pf-coupling-audit.md finding 1.
    $result = $processor(sensitiveDataTestRecord('subject registered', [
        'cui' => 'RO12345678',
        'codFiscal' => '12345678',
        'nrRegCom' => 'J40/1234/2020',
        'fiscalIdentifier' => 'RO87654321',
    ]));

    expect($result->context['cui'])->toBe('[REDACTED]')
        ->and($result->context['codFiscal'])->toBe('[REDACTED]')
        ->and($result->context['nrRegCom'])->toBe('[REDACTED]')
        ->and($result->context['fiscalIdentifier'])->toBe('[REDACTED]');
});
