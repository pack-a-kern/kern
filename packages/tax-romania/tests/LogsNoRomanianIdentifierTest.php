<?php

declare(strict_types=1);

use Kern\Platform\Logging\Redactions;
use Kern\Platform\Logging\SensitiveDataProcessor;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * A CNP, a CUI or an ANAF safety code must never reach a log file.
 *
 * The redactor is `kern/platform`'s and knows the shapes every application shares — a password, a
 * token, an IBAN, a JWT. It cannot know that thirteen digits beginning 1-8 are a person's identifier,
 * or that "cod de siguranță" precedes a one-time code, so this package declares both in
 * `TaxRomaniaServiceProvider::declareWhatMustNeverBeLogged()`.
 *
 * The test lives here for one reason: if that declaration is ever deleted, *this* suite fails. A
 * declaration nobody checks is a leak nobody notices, and the check belongs beside the words.
 */
/** @param array<array-key, mixed> $context */
function logRecord(string $message, array $context = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable('2026-09-25T10:00:00Z'),
        channel: 'test',
        level: Level::Info,
        message: $message,
        context: $context,
    );
}

function romanianRedactor(): SensitiveDataProcessor
{
    // The container's registry, which this package's provider has already declared into.
    return new SensitiveDataProcessor(app(Redactions::class));
}

it('redacts a CNP written into a message, by its shape alone', function (): void {
    $result = romanianRedactor()(logRecord('subject cnp is 1900101221144'));

    expect($result->message)->not->toContain('1900101221144')
        ->and($result->message)->toContain('[REDACTED]');
});

it('redacts a company\'s fiscal identifiers by key name', function (): void {
    // A PFA's identifier is a CUI, not a CNP. Redacting only `cnp` would leak the equivalent for
    // every non-PF subject — see docs/reference/pf-coupling-audit.md finding 1.
    $result = romanianRedactor()(logRecord('subject registered', [
        'cnp' => '1900101221144',
        'cui' => 'RO12345678',
        'codFiscal' => '12345678',
        'nrRegCom' => 'J40/1234/2020',
        'fiscalIdentifier' => 'RO87654321',
    ]));

    expect($result->context['cnp'])->toBe('[REDACTED]')
        ->and($result->context['cui'])->toBe('[REDACTED]')
        ->and($result->context['codFiscal'])->toBe('[REDACTED]')
        ->and($result->context['nrRegCom'])->toBe('[REDACTED]')
        ->and($result->context['fiscalIdentifier'])->toBe('[REDACTED]');
});

it('keeps the label of an ANAF safety code and drops the code', function (): void {
    $result = romanianRedactor()(logRecord('SPV: cod de siguranță: 482913 acceptat'));

    expect($result->message)->not->toContain('482913')
        ->and($result->message)->toContain('cod de siguranță: [REDACTED]');
});
