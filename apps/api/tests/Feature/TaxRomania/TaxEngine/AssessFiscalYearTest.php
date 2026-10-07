<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\TaxEngine\AssessFiscalYearTest;

use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Kern\Legislation\Models\SourceSection;
use Kern\Platform\Identity\Enums\MfaMethodType;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserMfaMethod;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameter;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\TaxEngine\Actions\AssessFiscalYear;
use Kern\TaxRomania\TaxEngine\Actions\BuildTaxFactsFromLedger;
use Kern\TaxRomania\TaxEngine\Contracts\LedgerIncomeProvider;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\LedgerIncomeEntry;
use Kern\TaxRomania\TaxEngine\Enums\AssessmentStatus;
use Kern\TaxRomania\TaxEngine\Enums\FiscalParameterValueType;
use Kern\TaxRomania\TaxEngine\Enums\TaxFactStatus;
use Kern\TaxRomania\TaxEngine\Exceptions\NoTaxRulesRegisteredException;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessmentLine;
use Kern\TaxRomania\TaxEngine\Models\TaxFact;
use Kern\TaxRomania\TaxRules\TaxRuleSource;
use Kern\TaxRomania\TaxRules\TaxRuleVersion;

/**
 * The ledger to a recorded assessment, end to end.
 *
 * The thing that did not exist: `TaxAssessor` had been built and tested and never called, so the engine could
 * compute and nothing ever asked it to.
 *
 * The ledger is a fake implementing the port rather than real transactions, for the reason the port exists —
 * what is under test is the engine's path from income to figures, not Wallet's tables. Parameters and rule
 * versions are real rows, because those are what the resolvers read and faking them would skip the resolution
 * being tested.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

/**
 * A subject with a tenant bound, because every table here is tenant-scoped.
 *
 * The scope is the thing that keeps one taxpayer's figures away from another's, so a test that bypassed it
 * would be testing a query no request can make.
 */
/**
 * The owner of the subject under test, remembered so nothing has to guess at it.
 *
 * `User::query()->firstOrFail()` used to stand in for this, and `insertApprovedFiscalParameterVersion` creates a
 * user of its own for `approved_by` — five of them — so the test authenticated as whichever row came back first.
 * On sqlite that happened to be the owner; on Postgres it was not, so a test asserting the step-up gate refuses
 * instead found a user with no authenticator and watched the request sail through. It passed alone and failed in
 * the suite, which is the worst shape a test failure takes.
 */
function subjectOwner(): User
{
    /** @var User $user */
    $user = User::query()->findOrFail($GLOBALS['assessableOwnerId']);

    return $user;
}

function assessableSubject(): TaxSubject
{
    $subject = TaxSubject::factory()->create();
    $user = User::factory()->create();
    $GLOBALS['assessableOwnerId'] = $user->id;

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    app(TenantContext::class)->authorizeAndActivate($user->id, $subject->id);

    return $subject;
}

/**
 * The parameters the three real rules read, with values this test chose.
 *
 * Real rows rather than a stub, because `ParameterResolver` is part of what is under test here — it is the
 * step that turns "this rule needs RO.MIN_WAGE" into a value valid on a date. The numbers are the test's own:
 * 1,000 per minimum wage makes the thresholds 6,000 / 12,000 / 24,000 and keeps every assertion checkable.
 */
function seedRuleParameters(): void
{
    // The type is on the parameter, not the version, and the accessors check it — asking a MONEY parameter for
    // a decimal rate is refused rather than coerced, which is why each one states its own.
    $values = [
        'RO.MIN_WAGE' => [FiscalParameterValueType::MONEY, ['amount_minor' => 100000, 'currency' => 'RON']],
        'RO.CASS.RATE' => [FiscalParameterValueType::DECIMAL, ['value' => '0.10']],
        'RO.CASS.OTHER_INCOME_BANDS_WAGES' => [FiscalParameterValueType::JSON, ['wages' => [6, 12, 24]]],
        'RO.INCOME_TAX.RATE' => [FiscalParameterValueType::DECIMAL, ['value' => '0.10']],
        'RO.INCOME_TAX.RENT_EXPENSE_QUOTA' => [FiscalParameterValueType::DECIMAL, ['value' => '0.20']],
        // Independent activity owes two contributions on the same income, computed two different ways: the
        // pension one steps up at thresholds, the health one sits between a floor and a cap.
        'RO.CAS.RATE' => [FiscalParameterValueType::DECIMAL, ['value' => '0.25']],
        'RO.CAS.BASE_MIN_WAGES' => [FiscalParameterValueType::JSON, ['wages' => [12, 24]]],
        'RO.CASS.INDEPENDENT_BASE_WAGES' => [FiscalParameterValueType::JSON, ['floor' => 6, 'cap' => 72]],
    ];

    foreach ($values as $code => [$valueType, $valueJson]) {
        $parameter = FiscalParameter::factory()->create(['code' => $code, 'value_type' => $valueType->value]);

        /*
         * Through the suite's own helper, which inserts the row directly.
         *
         * Setting APPROVED through the model is refused — `AgentApprovalNotPermittedException`, because
         * approving a fiscal value is a human act and application code may not perform it. A test needs an
         * approved value as a *precondition* rather than as an action, and the helper exists for exactly that.
         *
         * Approved matters here beyond realism: only an approved version's source section may be cited by a
         * rule, since a candidate's is a section nobody has agreed on yet.
         */
        insertApprovedFiscalParameterVersion(
            $parameter->id,
            SourceSection::factory()->create()->id,
            ['valid_from' => '2026-01-01', 'value_json' => json_encode($valueJson, JSON_THROW_ON_ERROR)],
        );
    }
}

/** @param list<LedgerIncomeEntry> $entries */
function fakeLedger(array $entries): void
{
    app()->instance(LedgerIncomeProvider::class, new class ($entries) implements LedgerIncomeProvider {
        /** @param list<LedgerIncomeEntry> $entries */
        public function __construct(private array $entries)
        {
        }

        public function incomeIn(string $taxSubjectId, int $fiscalYear, array $categoryCodes): array
        {
            return array_values(array_filter(
                $this->entries,
                static fn (LedgerIncomeEntry $entry): bool => in_array($entry->categoryCode, $categoryCodes, true),
            ));
        }
    });
}

it('builds a fact per ledger entry, proposed rather than confirmed', function (): void {
    $subject = assessableSubject();

    fakeLedger([
        new LedgerIncomeEntry('01a0ec10-0000-7000-8000-000000000001', 'DIVIDENDS_INTEREST', 500000, 'RON', new DateTimeImmutable('2026-03-01')),
        new LedgerIncomeEntry('01a0ec10-0000-7000-8000-000000000002', 'RENT', 1200000, 'RON', new DateTimeImmutable('2026-06-01')),
        // Not income: the vocabulary separates a person's own money moving, and the producer reads that group.
        new LedgerIncomeEntry('01a0ec10-0000-7000-8000-000000000003', 'TRANSFER', 9900000, 'RON', new DateTimeImmutable('2026-06-01')),
    ]);

    $facts = app(BuildTaxFactsFromLedger::class)($subject->id, 2026);

    expect($facts)->toHaveCount(2);

    $categories = TaxFact::query()->where('fiscal_year', 2026)->pluck('category_code')->sort()->values()->all();

    expect($categories)->toBe(['DIVIDENDS_INTEREST', 'RENT'])
        // Proposed, because it rests on somebody's categorisation of a bank line. §14.6 reserves CONFIRMED
        // for what a person has affirmed, and a filing should be able to tell the difference.
        ->and(TaxFact::query()->where('status', TaxFactStatus::PROPOSED->value)->count())->toBe(2);
});

it('leaves salary alone, because an employer already withheld and declared it', function (): void {
    $subject = assessableSubject();

    fakeLedger([
        new LedgerIncomeEntry('01a0ec10-0000-7000-8000-000000000001', 'SALARY_PENSION', 6000000, 'RON', new DateTimeImmutable('2026-03-01')),
    ]);

    expect(app(BuildTaxFactsFromLedger::class)($subject->id, 2026))->toBe([]);
});

it('replaces its own facts on a rerun rather than adding beside them', function (): void {
    // A fact is a reading of the ledger as it stands, so a corrected ledger should give a corrected fact. Two
    // runs leaving two facts would double every figure computed from them.
    $subject = assessableSubject();

    fakeLedger([
        new LedgerIncomeEntry('01a0ec10-0000-7000-8000-000000000001', 'DIVIDENDS_INTEREST', 500000, 'RON', new DateTimeImmutable('2026-03-01')),
    ]);

    app(BuildTaxFactsFromLedger::class)($subject->id, 2026);
    app(BuildTaxFactsFromLedger::class)($subject->id, 2026);

    expect(TaxFact::query()->where('fiscal_year', 2026)->count())->toBe(1);
});

it('names the split a fact came from, so the evidence stays reachable', function (): void {
    $subject = assessableSubject();

    fakeLedger([
        new LedgerIncomeEntry('01a0ec10-0000-7000-8000-000000000042', 'DIVIDENDS_INTEREST', 500000, 'RON', new DateTimeImmutable('2026-03-01')),
    ]);

    app(BuildTaxFactsFromLedger::class)($subject->id, 2026);

    /** @var TaxFact $fact */
    $fact = TaxFact::query()->where('fiscal_year', 2026)->firstOrFail();

    // A uuid, because the column is one — sqlite would have taken "split-42" and Postgres refuses it, which
    // is the whole reason this suite runs on both.
    expect($fact->source_id)->toBe('01a0ec10-0000-7000-8000-000000000042')
        ->and($fact->source_type)->toBe(BuildTaxFactsFromLedger::SOURCE_TYPE);
});

it('says what to do when no rules are registered, which is a fresh installation', function (): void {
    /*
     * `TaxContext` refuses an empty rule list already, with "missing required field ruleCodes" — true, and no
     * help at all to somebody whose rules exist in code and have no row pointing at them.
     */
    $subject = assessableSubject();
    fakeLedger([]);

    expect(fn () => app(AssessFiscalYear::class)($subject->id, 2026))
        ->toThrow(NoTaxRulesRegisteredException::class);
});

it('assesses a registered rule as a draft, never a final one', function (): void {
    /*
     * The facts it builds are PROPOSED and `kern:tax:register-rules` records CANDIDATE versions, so a final
     * assessment would be refused by both resolvers. This action offering no way to ask for one is the point:
     * somebody reads the draft first, and filing is a separate deliberate act.
     */
    $subject = assessableSubject();
    fakeLedger([]);

    seedRuleParameters();
    Artisan::call('kern:tax:register-rules');

    $assessment = app(AssessFiscalYear::class)($subject->id, 2026);

    expect($assessment->status)->toBe(AssessmentStatus::DRAFT)
        ->and($assessment->fiscal_year)->toBe(2026);
});

it('turns a year of real income into figures, through every step at once', function (): void {
    /*
     * The whole path: a ledger entry becomes a fact, the fact reaches three rules, each produces a line, and
     * the lines are recorded with their traces. Every piece has its own test; this is the one that fails if
     * they stop fitting together.
     *
     * 8,000 of dividends and 12,000 of rent. The dividends are withheld at source so no income tax is computed
     * on them, the rent is not so 10% of (12,000 − 20%) is, and both count toward the contribution — 20,000
     * reaches the 12-wage threshold, so CASS is 10% of 12,000.
     */
    $subject = assessableSubject();
    seedRuleParameters();
    Artisan::call('kern:tax:register-rules');

    fakeLedger([
        new LedgerIncomeEntry('01a0ec10-0000-7000-8000-000000000001', 'DIVIDENDS_INTEREST', 800000, 'RON', new DateTimeImmutable('2026-03-01')),
        new LedgerIncomeEntry('01a0ec10-0000-7000-8000-000000000002', 'RENT', 1200000, 'RON', new DateTimeImmutable('2026-06-01')),
    ]);

    $assessment = app(AssessFiscalYear::class)($subject->id, 2026);

    $byCategory = [];

    foreach (TaxAssessmentLine::query()->where('assessment_id', $assessment->id)->get() as $line) {
        $byCategory[$line->category_code] = [$line->taxable_base_minor, $line->tax_minor];
    }

    expect($byCategory['RENT'])->toBe([960000, 96000])
        ->and($byCategory['OTHER_INCOME'])->toBe([1200000, 120000])
        // No crypto in the ledger, so a zero line rather than a missing one: "you owe nothing, and here is the
        // arithmetic" is an answer, and a missing line cannot be told from a rule nobody ran.
        ->and($byCategory['CRYPTO_GAIN'])->toBe([0, 0]);
});

it('computes a year over HTTP, behind the gate that guards sensitive acts', function (): void {
    /*
     * The endpoint, which is what made the action more than a tested orphan. Behind `verify-recent` because
     * assessing reads a year of somebody's finances and writes figures they may file.
     */
    $subject = assessableSubject();
    seedRuleParameters();
    Artisan::call('kern:tax:register-rules');

    fakeLedger([
        new LedgerIncomeEntry('01a0ec10-0000-7000-8000-000000000001', 'RENT', 1200000, 'RON', new DateTimeImmutable('2026-06-01')),
    ]);

    $user = subjectOwner();

    UserMfaMethod::create([
        'user_id' => $user->id,
        'type' => MfaMethodType::TOTP,
        'secret_ciphertext' => 'JBSWY3DPEHPK3PXP',
        'confirmed_at' => now('UTC'),
    ]);

    // Enrolled but not recently proved: the gate should stop this before anything is computed. Withdrawn
    // explicitly, because the session store carries a marker an earlier test may have written.
    forgetVerification();

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subject->id}/tax/assessments", ['fiscal_year' => 2026])
        ->assertForbidden()
        ->assertJsonPath('reason', 'VERIFICATION_REQUIRED');

    verifySession();

    $response = $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subject->id}/tax/assessments", ['fiscal_year' => 2026])
        ->assertCreated()
        ->assertJsonPath('status', 'DRAFT')
        ->assertJsonPath('fiscal_year', 2026);

    /** @var list<array<string, mixed>> $lines */
    $lines = $response->json('lines');
    $rent = null;

    foreach ($lines as $line) {
        if (($line['category_code'] ?? null) === 'RENT') {
            $rent = $line;
        }
    }

    // 12,000 less the 20% quota, at 10%. And the explanation key travels with it, because a figure without its
    // reasoning is what this engine exists not to produce.
    expect($rent['taxable_base_minor'] ?? null)->toBe(960000)
        ->and($rent['tax_minor'] ?? null)->toBe(96000)
        ->and($rent['explanation_key'] ?? null)->toBe('tax.ro.pf.income_tax.rent')
        ->and($rent['trace'] ?? null)->toBeArray();
});

it('refuses a year outside the fiscal dataset rather than failing deep in the resolver', function (): void {
    $subject = assessableSubject();
    $user = subjectOwner();

    UserMfaMethod::create([
        'user_id' => $user->id,
        'type' => MfaMethodType::TOTP,
        'secret_ciphertext' => 'JBSWY3DPEHPK3PXP',
        'confirmed_at' => now('UTC'),
    ]);

    verifySession();

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subject->id}/tax/assessments", ['fiscal_year' => 1200])
        ->assertStatus(422)
        ->assertJsonValidationErrors('fiscal_year');
});

/**
 * Which threshold the contribution reached, out of a response body typed `mixed`.
 *
 * Narrowed in one place rather than indexed through, so the assertion reads as the thing being checked instead
 * of as three guards around it.
 *
 * @param  array<string, mixed>|null  $line
 */
function cassThreshold(?array $line): ?string
{
    $reached = $line === null ? null : ($line['base_reached'] ?? null);
    $wages = is_array($reached) ? ($reached['threshold_in_wages'] ?? null) : null;

    return is_string($wages) ? $wages : null;
}

it('shows what was earned beside what was taxed, which the first screen did not', function (): void {
    /*
     * The contribution is a percentage of a threshold rather than of income, and the first version of this
     * response carried only the threshold — so a screen built on it displayed 103,800 with no sign of the
     * 475,690 behind it, while the explanation said "not of what you earned".
     */
    $subject = assessableSubject();
    seedRuleParameters();
    Artisan::call('kern:tax:register-rules');

    fakeLedger([
        new LedgerIncomeEntry('01a0ec10-0000-7000-8000-000000000001', 'DIVIDENDS_INTEREST', 3000000, 'RON', new DateTimeImmutable('2026-03-01')),
    ]);

    $user = subjectOwner();

    UserMfaMethod::create([
        'user_id' => $user->id,
        'type' => MfaMethodType::TOTP,
        'secret_ciphertext' => 'JBSWY3DPEHPK3PXP',
        'confirmed_at' => now('UTC'),
    ]);

    verifySession();

    $response = $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subject->id}/tax/assessments", ['fiscal_year' => 2026])
        ->assertCreated();

    /** @var list<array<string, mixed>> $lines */
    $lines = $response->json('lines');
    $cass = null;

    foreach ($lines as $line) {
        if (($line['tax_code'] ?? null) === 'CASS') {
            $cass = $line;
        }
    }

    // 30,000 of income reaches the 24-wage threshold at 1,000 per wage, so the base is 24,000 and the
    // contribution 2,400 — and the income is on the line rather than only inside the trace.
    expect($cass['income'] ?? null)->toBe('30000.00 RON')
        ->and($cass['taxable_base_minor'] ?? null)->toBe(2400000)
        ->and($cass['tax_minor'] ?? null)->toBe(240000)
        ->and(cassThreshold($cass))->toBe('24');
});

it('cites the law its parameters were approved against, rather than citing nothing', function (): void {
    /*
     * `source_refs: []` on every trace meant a figure arrived citing no law at all — the provenance chain broken
     * at its last link, which `BLUEPRINT.md` §30 and ADR 0004 make mandatory.
     *
     * Which article a rule *implements* is a legal determination nothing here may invent. But every approved
     * parameter version already names a section somebody approved, and a rule declares the parameters it reads,
     * so the sections behind its values are derivable from approvals that exist.
     */
    $subject = assessableSubject();
    seedRuleParameters();
    Artisan::call('kern:tax:register-rules');

    fakeLedger([
        new LedgerIncomeEntry('01a0ec10-0000-7000-8000-000000000001', 'RENT', 1200000, 'RON', new DateTimeImmutable('2026-06-01')),
    ]);

    $assessment = app(AssessFiscalYear::class)($subject->id, 2026);

    /** @var TaxAssessmentLine $rent */
    $rent = TaxAssessmentLine::query()
        ->where('assessment_id', $assessment->id)
        ->where('category_code', 'RENT')
        ->firstOrFail();

    // Two parameters, one approved version each in this test, so two sections.
    expect($rent->calculation_trace_json['source_refs'] ?? [])->toHaveCount(2);
});

it('leaves an already-recorded rule version alone while filling in citations it lacks', function (): void {
    // A version may not be repointed at new code — `tax_assessment_lines` holds a foreign key to it, and replay
    // is the reason versions exist. Adding the citations it never had takes nothing away.
    assessableSubject();
    seedRuleParameters();

    Artisan::call('kern:tax:register-rules');
    $before = TaxRuleVersion::query()->count();

    Artisan::call('kern:tax:register-rules');

    expect(TaxRuleVersion::query()->count())->toBe($before)
        ->and(TaxRuleSource::query()->count())->toBeGreaterThan(0);
});

it('keeps one draft per year, replacing the previous one', function (): void {
    /*
     * A draft is a working document. Pressing the button again means something changed, so the earlier answer is
     * wrong — and keeping it produced fifty-two rows for eight years with nothing marking which was current.
     */
    $subject = assessableSubject();
    seedRuleParameters();
    Artisan::call('kern:tax:register-rules');
    fakeLedger([]);

    app(AssessFiscalYear::class)($subject->id, 2026);
    app(AssessFiscalYear::class)($subject->id, 2026);
    app(AssessFiscalYear::class)($subject->id, 2026);

    expect(TaxAssessment::query()->where('fiscal_year', 2026)->count())->toBe(1)
        // The lines of the discarded drafts go with them; nothing is left pointing at an assessment that is gone.
        ->and(TaxAssessmentLine::query()->count())->toBe(
            TaxAssessmentLine::query()
                ->whereIn('assessment_id', TaxAssessment::query()->select('id'))
                ->count(),
        );
});

it('keeps a draft per year rather than one draft overall', function (): void {
    $subject = assessableSubject();
    seedRuleParameters();
    Artisan::call('kern:tax:register-rules');
    fakeLedger([]);

    // Two years this fixture's parameters cover — they start in 2026 with no end, so 2025 has no rule in force
    // and would fail for an unrelated reason.
    app(AssessFiscalYear::class)($subject->id, 2026);
    app(AssessFiscalYear::class)($subject->id, 2027);

    expect(TaxAssessment::query()->count())->toBe(2);
});

it('leaves a final assessment alone, and lets the new draft sit beside it', function (): void {
    // A filing rests on a FINAL assessment, so a recomputation must never discard one — and having both is the
    // comparison somebody would actually want.
    $subject = assessableSubject();
    seedRuleParameters();
    Artisan::call('kern:tax:register-rules');
    fakeLedger([]);

    $draft = app(AssessFiscalYear::class)($subject->id, 2026);
    $draft->status = AssessmentStatus::FINAL;
    $draft->save();

    app(AssessFiscalYear::class)($subject->id, 2026);

    expect(TaxAssessment::query()->where('fiscal_year', 2026)->count())->toBe(2)
        ->and(TaxAssessment::query()->where('status', AssessmentStatus::FINAL->value)->count())->toBe(1);
});
