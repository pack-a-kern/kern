<?php

declare(strict_types=1);

use App\Domain\TaxEngine\Enums\AssessmentStatus;
use App\Domain\TaxEngine\Exceptions\IncompleteTaxContextException;
use App\Domain\TaxEngine\TaxContext;

it('builds immutably, each call returning a new instance', function (): void {
    $base = TaxContext::forSubject('subject-1');
    $withYear = $base->fiscalYear(2026);

    expect($base->fiscalYear)->toBeNull()
        ->and($withYear->fiscalYear)->toBe(2026)
        ->and($withYear)->not->toBe($base);
});

it('assembles the full BLUEPRINT.md §11.1 builder chain', function (): void {
    $asOf = new DateTimeImmutable('2026-05-20');

    $context = TaxContext::forSubject('subject-1')
        ->fiscalYear(2026)
        ->asOf($asOf)
        ->forRules(['RO.TEST.RULE'])
        ->withFacts([])
        ->withRulesetId('RO-TEST-2026@1.0.0');

    expect($context->taxSubjectId)->toBe('subject-1')
        ->and($context->fiscalYear)->toBe(2026)
        ->and($context->asOf)->toBe($asOf)
        ->and($context->ruleCodes)->toBe(['RO.TEST.RULE'])
        ->and($context->rulesetId)->toBe('RO-TEST-2026@1.0.0')
        ->and($context->status)->toBe(AssessmentStatus::DRAFT);
});

it('defaults to DRAFT and switches explicitly to FINAL', function (): void {
    $draft = TaxContext::forSubject('s')->fiscalYear(2026)->asOf(new DateTimeImmutable())->forRules(['R']);

    expect($draft->status)->toBe(AssessmentStatus::DRAFT)
        ->and($draft->final()->status)->toBe(AssessmentStatus::FINAL)
        ->and($draft->final()->draft()->status)->toBe(AssessmentStatus::DRAFT);
});

it('refuses to proceed when fiscal year / as-of / rule codes are still unset', function (): void {
    $incomplete = TaxContext::forSubject('s'); // no fiscalYear/asOf/ruleCodes yet

    expect(fn () => $incomplete->assertComplete())->toThrow(IncompleteTaxContextException::class);
});

it('requires at least one rule code', function (): void {
    $context = TaxContext::forSubject('s')->fiscalYear(2026)->asOf(new DateTimeImmutable());

    expect(fn () => $context->assertComplete())->toThrow(IncompleteTaxContextException::class);
});
