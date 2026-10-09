<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kern\TaxRomania\Database\Factories\FinancialAccountFactory;
use Kern\TaxRomania\TaxEngine\Actions\AssessFiscalYear;
use Kern\TaxRomania\TaxEngine\Contracts\LedgerIncomeProvider;
use Kern\TaxRomania\TaxEngine\Enums\AssessmentStatus;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Providers\EloquentLedgerIncomeProvider;

/**
 * Reading back the assessment a year already has.
 *
 * The screen was blank on arrival and the only way to see a saved draft was to compute a new one — which
 * replaces it, so looking for last week's figures destroyed them.
 */
beforeEach(function (): void {
    seedRuleParameters();
    Artisan::call('kern:tax:register-rules');
    fakeLedger([]);
});

it('returns the saved draft without computing a new one', function (): void {
    $subject = assessableSubject();
    $saved = app(AssessFiscalYear::class)($subject->id, 2026);

    $response = $this->actingAs(subjectOwner())
        ->getJson("/api/v1/subjects/{$subject->id}/tax/assessments?fiscal_year=2026");

    $response->assertOk()
        ->assertJsonPath('assessment.id', $saved->id)
        ->assertJsonPath('assessment.fiscal_year', 2026);

    // The read did not produce a second one, which is the whole point of reading rather than reassessing.
    expect(TaxAssessment::query()->count())->toBe(1);
});

it('says nothing is there, rather than failing, for a year never assessed', function (): void {
    $subject = assessableSubject();

    $this->actingAs(subjectOwner())
        ->getJson("/api/v1/subjects/{$subject->id}/tax/assessments?fiscal_year=2024")
        ->assertOk()
        // Not a 404: nothing is missing, it has not been done — and a screen reading that as an error shows a
        // failure where it should show a button. Still a 200, because the picker's years ride along and a 204
        // has no body to carry them in.
        ->assertJsonPath('assessment', null)
        ->assertJsonStructure(['assessment', 'years']);
});

it('returns the year\'s one row, final or draft', function (): void {
    // There is never a choice to make: finalising replaces the draft, so a year holds exactly one assessment.
    $subject = assessableSubject();
    app(AssessFiscalYear::class)($subject->id, 2026);
    approveRegisteredRules();
    $final = app(AssessFiscalYear::class)($subject->id, 2026, null, AssessmentStatus::FINAL);

    $this->actingAs(subjectOwner())
        ->getJson("/api/v1/subjects/{$subject->id}/tax/assessments?fiscal_year=2026")
        ->assertOk()
        ->assertJsonPath('assessment.id', $final->id)
        ->assertJsonPath('assessment.status', AssessmentStatus::FINAL->value);
});

it('carries the figures a screen needs, in the shape the computing endpoint returns', function (): void {
    /*
     * The two paths share a presenter, and this is what that is for: a draft somebody returns to tomorrow has
     * to arrive exactly as the one they watched appear, or a figure renders on one path and a dash on the other.
     */
    $subject = assessableSubject();
    app(AssessFiscalYear::class)($subject->id, 2026);

    /** @var array<string, mixed> $read */
    $read = $this->actingAs(subjectOwner())
        ->getJson("/api/v1/subjects/{$subject->id}/tax/assessments?fiscal_year=2026")
        ->assertOk()
        ->json('assessment');

    expect(array_keys($read))->toEqualCanonicalizing([
        'id', 'fiscal_year', 'status', 'computed_at', 'lines', 'totals', 'income_assessed_minor',
    ])->and($read['computed_at'])->toBeString();
});

it('refuses a year outside the fiscal dataset', function (): void {
    $subject = assessableSubject();

    $this->actingAs(subjectOwner())
        ->getJson("/api/v1/subjects/{$subject->id}/tax/assessments?fiscal_year=1200")
        ->assertStatus(422);
});

it('offers the years the ledger actually has, newest first', function (): void {
    /*
     * Distinct years, not the range between the first and the last. A taxpayer with one transaction in 2019
     * and one in 2026 should see two options, not eight — the list exists to stop somebody assessing years
     * they were never a taxpayer in.
     *
     * Runs on both databases on purpose: the expression behind this is chosen per driver, because
     * `extract(year from …)` is Postgres-only and fails silently by passing in the other lane.
     */
    $subject = assessableSubject();

    /*
     * The real provider, not the fake the other tests bind: this is about a SQL expression over `booked_at`,
     * and a fake that answers from a list of entries would assert nothing about the thing that differs
     * between the two databases.
     */
    app()->instance(LedgerIncomeProvider::class, app(EloquentLedgerIncomeProvider::class));

    /*
     * Inserted rather than built through the model: this asserts a SQL expression over `booked_at`, and the
     * row needs nothing else to be true for that. Going through the model would drag in encryption and a
     * ledger account for a test about which years a `group by` returns.
     */
    // The model does not expose `factory()`; the factory class is reached directly, as its namespace sits
    // in the package rather than the app.
    /** @var FinancialAccount $account */
    $account = FinancialAccountFactory::new()->createOne(['tax_subject_id' => $subject->id]);

    foreach (['2019-04-02', '2019-11-30', '2026-01-09'] as $bookedAt) {
        DB::table('financial_transactions')->insert([
            'id' => (string) Str::uuid7(),
            'tax_subject_id' => $subject->id,
            'account_id' => $account->id,
            'booked_at' => $bookedAt . ' 09:00:00',
            'amount_minor' => 1000,
            'currency_code' => 'RON',
            'description_ciphertext' => 'x',
            'counterparty_ciphertext' => 'x',
            'classification_status' => 'UNCLASSIFIED',
            'source' => 'MANUAL',
            'created_at' => now(),
        ]);
    }

    $years = $this->actingAs(subjectOwner())
        ->getJson("/api/v1/subjects/{$subject->id}/tax/assessments?fiscal_year=2026")
        ->assertOk()
        ->json('years');

    expect($years)->toBe([2026, 2019]);
});

it('offers nothing for a taxpayer with no records', function (): void {
    $subject = assessableSubject();

    app()->instance(LedgerIncomeProvider::class, app(EloquentLedgerIncomeProvider::class));

    $this->actingAs(subjectOwner())
        ->getJson("/api/v1/subjects/{$subject->id}/tax/assessments?fiscal_year=2026")
        ->assertOk()
        ->assertJsonPath('years', []);
});
