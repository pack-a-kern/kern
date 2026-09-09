<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\TaxKnowledge\ForbidsAgentApprovalTest;

use App\Domain\TaxKnowledge\Enums\RuleApprovalStatus;
use App\Domain\TaxKnowledge\Exceptions\AgentApprovalNotPermittedException;
use App\Domain\TaxKnowledge\Models\FiscalParameterVersion;
use App\Domain\TaxKnowledge\Models\TaxRuleVersion;
use Illuminate\Support\Facades\Artisan;

/**
 * `docs/AGENTS.md` §9 / `BLUEPRINT.md` §9.3: "No agent may set APPROVED." Proves the rule is
 * enforced in code (`App\Domain\TaxKnowledge\Concerns\ForbidsAgentApproval`), not only by review —
 * this task's explicit, testable requirement.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

it('refuses to create a fiscal_parameter_versions row with status APPROVED', function (): void {
    FiscalParameterVersion::factory()->create(['status' => RuleApprovalStatus::APPROVED]);
})->throws(AgentApprovalNotPermittedException::class);

it('refuses to create a fiscal_parameter_versions row with the raw string "APPROVED"', function (): void {
    FiscalParameterVersion::factory()->create(['status' => 'APPROVED']);
})->throws(AgentApprovalNotPermittedException::class);

it('refuses to transition an existing fiscal_parameter_versions row to APPROVED via save()', function (): void {
    $version = FiscalParameterVersion::factory()->create(['status' => RuleApprovalStatus::CANDIDATE]);

    $version->status = RuleApprovalStatus::APPROVED;
    $version->save();
})->throws(AgentApprovalNotPermittedException::class);

it('refuses to create a tax_rule_versions row with status APPROVED', function (): void {
    TaxRuleVersion::factory()->create(['status' => RuleApprovalStatus::APPROVED]);
})->throws(AgentApprovalNotPermittedException::class);

it('refuses to transition an existing tax_rule_versions row to APPROVED via save()', function (): void {
    $version = TaxRuleVersion::factory()->create(['status' => RuleApprovalStatus::CANDIDATE]);

    $version->status = RuleApprovalStatus::APPROVED;
    $version->save();
})->throws(AgentApprovalNotPermittedException::class);

it('allows every non-APPROVED status on both models', function (): void {
    foreach ([RuleApprovalStatus::CANDIDATE, RuleApprovalStatus::SUPERSEDED, RuleApprovalStatus::REJECTED] as $status) {
        $parameterVersion = FiscalParameterVersion::factory()->create(['status' => $status]);
        expect($parameterVersion->status)->toBe($status);

        $ruleVersion = TaxRuleVersion::factory()->create(['status' => $status]);
        expect($ruleVersion->status)->toBe($status);
    }
});
