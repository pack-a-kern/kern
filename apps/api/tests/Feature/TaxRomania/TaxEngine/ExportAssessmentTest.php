<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Kern\Audit\Models\AuditEvent;
use Kern\TaxRomania\TaxEngine\Actions\AssessFiscalYear;

/**
 * An assessment as two CSVs.
 */
beforeEach(function (): void {
    seedRuleParameters();
    Artisan::call('kern:tax:register-rules');
    fakeLedger([]);
});

it('exports the figures with the law each rests on', function (): void {
    $subject = assessableSubject();
    app(AssessFiscalYear::class)($subject->id, 2026);

    $body = $this->actingAs(subjectOwner())
        ->get("/api/v1/subjects/{$subject->id}/tax/assessments/export?fiscal_year=2026&part=figures")
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->getContent();

    expect($body)
        ->toContain('tax,category,income_ron,taxable_base_ron,rate,tax_ron,explanation_key,rule_version,source_sections')
        ->toContain('CASS')
        // A BOM, or Excel on Windows reads UTF-8 as the system codepage and mangles every diacritic.
        ->toStartWith("\u{FEFF}");
});

it('exports the evidence without decrypting anything', function (): void {
    /*
     * Counterparty and description are encrypted at rest. Writing them into a file that leaves the system
     * turns an export into a decryption — a different act, needing a different permission. The split id is
     * carried instead, which reaches the same row inside the app where access is scoped and recorded.
     */
    $subject = assessableSubject();
    app(AssessFiscalYear::class)($subject->id, 2026);

    $body = $this->actingAs(subjectOwner())
        ->get("/api/v1/subjects/{$subject->id}/tax/assessments/export?fiscal_year=2026&part=evidence")
        ->assertOk()
        ->getContent();

    expect($body)
        ->toContain('recognition_date,category,amount,currency,amount_ron,fx_rate,fx_rate_date')
        ->toContain('source_id')
        ->not->toContain('counterparty')
        ->not->toContain('description');
});

it('records the download in the audit chain', function (): void {
    // Nothing is decrypted, but a year's figures and their sources leaving the system is still an event
    // somebody may need to account for.
    $subject = assessableSubject();
    app(AssessFiscalYear::class)($subject->id, 2026);
    $before = AuditEvent::query()->withoutGlobalScopes()->count();

    $this->actingAs(subjectOwner())
        ->get("/api/v1/subjects/{$subject->id}/tax/assessments/export?fiscal_year=2026&part=figures")
        ->assertOk();

    expect(AuditEvent::query()->withoutGlobalScopes()->count())->toBe($before + 1)
        ->and(AuditEvent::query()->withoutGlobalScopes()->latest('id')->first()?->event_type)
        ->toBe('tax.assessment.exported');
});

it('refuses a part it does not produce', function (): void {
    $subject = assessableSubject();
    app(AssessFiscalYear::class)($subject->id, 2026);

    $this->actingAs(subjectOwner())
        ->getJson("/api/v1/subjects/{$subject->id}/tax/assessments/export?fiscal_year=2026&part=everything")
        ->assertStatus(422);
});

it('writes amounts a spreadsheet reads as numbers', function (): void {
    /*
     * `1234.56`, dot decimal, no grouping, currency in its own column. "1.234,56 RON" is text in most
     * locales, and a column of text that looks like money is worse than a column of numbers.
     */
    $subject = assessableSubject();
    app(AssessFiscalYear::class)($subject->id, 2026);

    $body = $this->actingAs(subjectOwner())
        ->get("/api/v1/subjects/{$subject->id}/tax/assessments/export?fiscal_year=2026&part=figures")
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain('RON"')->toMatch('/\d+\.\d{2}/');
});
