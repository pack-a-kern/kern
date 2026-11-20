<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Console\Commands\TaxEngine;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kern\Platform\Approval\ApprovalStatus;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\FiscalParameters\Registration\TaxRuleRegistrar;
use Kern\TaxRomania\TaxEngine\Attributes\TaxRule as TaxRuleAttribute;
use Kern\TaxRomania\TaxRules\TaxRule;
use Kern\TaxRomania\TaxRules\TaxRuleSource;
use Kern\TaxRomania\TaxRules\TaxRuleVersion;

/**
 * Records a `tax_rule_versions` row for every rule class that has none, as a `CANDIDATE`.
 *
 * The discovery and the actual recording live in {@see TaxRuleRegistrar}, shared with the screen's
 * Import button; this command is the terminal front for it — adding the dry-run, the optional
 * approval, and the per-rule reporting an operator reads.
 *
 * ## Why rules need registering at all
 *
 * The class is not the authority. `RuleResolver` reads a database row to decide which version applies on a
 * given date, and `#[TaxRule]` on the class is explicitly "a descriptive label on the class only". So a
 * rule that exists in code and not in the table is a rule the engine cannot find — which is the state all
 * three were in the moment they were written.
 *
 * ## Why CANDIDATE, and why that is not a shortcut
 *
 * A candidate version may back a `DRAFT` assessment and never a `FINAL` one — `RuleResolver` enforces that
 * and throws `CandidateNotAllowedInFinalAssessmentException`. So this makes a figure computable and
 * reviewable without making it fileable, which is the right order: somebody reads the draft first.
 *
 * Approving is not {@see TaxRuleRegistrar}'s business — `--approve-as` here is, deliberately, the only path
 * that approves a rule version, and it refuses unless the id names a real person holding
 * `fiscal.parameters.approve`. The screen's Import button never approves; it only brings the table level
 * with the code.
 *
 * ## It never changes what it finds
 *
 * A row for the same code and semantic version is left exactly as it is, including an approved one. Rules
 * are versioned precisely so a figure computed last year can be recomputed; silently repointing a version
 * at new code would destroy that, and is the one thing this must not do. Changed logic gets a new version
 * in its attribute. The one thing it does fill in on an existing row is missing citations, and (while the
 * version is still a candidate) a start date that an earlier bug recorded too late.
 */
final class RegisterTaxRulesCommand extends Command
{
    /** The same permission the fiscal parameter approval screen requires. Approving a rule is no smaller an act. */
    private const string APPROVAL_PERMISSION = 'fiscal.parameters.approve';

    protected $signature = 'kern:tax:register-rules
        {--dry-run : List what would be recorded and change nothing}
        {--approve-as= : The id of the person approving these versions, who must hold fiscal.parameters.approve}';

    protected $description = 'Record a candidate tax_rule_versions row for each rule class that has none';

    public function __construct(private readonly TaxRuleRegistrar $registrar)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run') === true;
        $approver = $this->approverFromOption();

        if ($approver === false) {
            return self::FAILURE;
        }

        $recorded = 0;

        foreach ($this->registrar->discover() as $discovered) {
            $class = $discovered['class'];
            $meta = $discovered['meta'];

            $existing = $this->existingVersion($meta->code, $meta->version);

            if ($existing !== null) {
                /*
                 * The version stays exactly as it is, and its citations are filled in if it has none.
                 * Repointing a version at new code would break the replay a version exists for; adding
                 * what was missing takes nothing away.
                 */
                $already = TaxRuleSource::query()->withoutGlobalScopes()
                    ->where('rule_version_id', $existing->id)
                    ->exists();

                if ($already) {
                    $this->line("  = {$meta->code} {$meta->version} already recorded");
                } else {
                    $cited = $this->registrar->citeParameterSources($existing, $meta->parameters);
                    $this->info("  ~ {$meta->code} {$meta->version} cited {$cited} source section(s) it had none of");
                }

                $this->correctStartDate($existing, $meta);
                $this->approveIfAsked($existing, $approver);

                continue;
            }

            $this->info("  + {$meta->code} {$meta->version} -> {$class}");
            $recorded++;

            if ($dryRun) {
                continue;
            }

            $version = $this->registrar->recordCandidate($class, $meta);

            $cited = TaxRuleSource::query()->withoutGlobalScopes()->where('rule_version_id', $version->id)->count();
            $this->line("    cites {$cited} source section(s), from its parameters' approvals");

            $this->approveIfAsked($version, $approver);
        }

        $this->newLine();
        $this->info($dryRun
            ? "{$recorded} version(s) would be recorded."
            : "{$recorded} version(s) recorded as CANDIDATE. Approve one before a FINAL assessment can use it.");

        return self::SUCCESS;
    }

    /** The already-recorded version for a code + semantic version, or null if there is none yet. */
    private function existingVersion(string $code, string $version): ?TaxRuleVersion
    {
        $rule = TaxRule::query()->withoutGlobalScopes()->where('code', $code)->first();

        if ($rule === null) {
            return null;
        }

        /** @var TaxRuleVersion|null $existing */
        $existing = TaxRuleVersion::query()->withoutGlobalScopes()
            ->where('tax_rule_id', $rule->id)
            ->where('semantic_version', $version)
            ->first();

        return $existing;
    }

    /**
     * The person approving, having checked they are entitled to.
     *
     * `ForbidsAgentApproval` says the one allowed path is "reached only from an authenticated request
     * holding `fiscal.parameters.approve` — never from a job, a command or a model's output". A flag here
     * departs from that sentence, deliberately and on instruction: there is no approval screen for rule
     * versions, so without it no version can ever be approved and no `FINAL` assessment can exist. The
     * invariant underneath — an approval names somebody accountable — is kept: this refuses unless the id
     * names a real person holding the same permission the screen would have demanded.
     *
     * @return string|false|null the id; false when the option was given and is unusable; null when not given
     */
    private function approverFromOption(): string|false|null
    {
        $option = $this->option('approve-as');

        if (! is_string($option) || $option === '') {
            return null;
        }

        $user = User::query()->find($option);

        if (! $user instanceof User) {
            $this->error("No user with id {$option}. An approval must name somebody who exists.");

            return false;
        }

        if (! $user->can(self::APPROVAL_PERMISSION)) {
            $this->error(
                'That person does not hold ' . self::APPROVAL_PERMISSION . ', which the approval screen would have '
                . 'required. Approving from a terminal does not lower the bar.',
            );

            return false;
        }

        return $user->id;
    }

    /**
     * Approves a version, when somebody asked and it is not already approved.
     *
     * Silent about one that is, rather than re-approving: the row names who decided and when, and overwriting
     * that with today's run would erase the record of the decision actually taken.
     */
    private function approveIfAsked(TaxRuleVersion $version, string|false|null $approver): void
    {
        if (! is_string($approver)) {
            return;
        }

        if ($version->status === ApprovalStatus::APPROVED) {
            $this->line('    already approved');

            return;
        }

        $version->approveByPerson($approver);

        $this->info('    approved');
    }

    /**
     * Widens a candidate version's start date when the stored one is wrong — only while it is a candidate,
     * and only ever earlier. Once a person has approved one, when it applies is part of what they approved,
     * and a command must not widen it behind them; narrowing could orphan an assessment already computed.
     */
    private function correctStartDate(TaxRuleVersion $version, TaxRuleAttribute $meta): void
    {
        $derived = $this->registrar->validFromOf($meta);
        $stored = $version->valid_from->format('Y-m-d');

        if ($derived >= $stored) {
            return;
        }

        if ($version->status !== ApprovalStatus::CANDIDATE) {
            $this->warn(
                "    {$meta->code} {$meta->version} is {$version->status->value} and starts {$stored}, but its "
                . "values go back to {$derived}. Record a new version to cover the earlier years.",
            );

            return;
        }

        $version->valid_from = Carbon::parse($derived);
        $version->save();

        $this->info("    starts {$derived} now, not {$stored} — its values go back that far");
    }
}
