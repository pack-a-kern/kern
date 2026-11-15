<?php

declare(strict_types=1);

namespace Kern\TaxRomania;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Kern\Investments\Events\InstrumentsWithdrawn;
use Kern\Investments\Events\IssueOnOffer;
use Kern\Legislation\Dependents\DocumentDependents;
use Kern\Legislation\Dependents\SectionDependents;
use Kern\Legislation\Requirements\NeedResolvers;
use Kern\Legislation\Requirements\NeedStatus;
use Kern\Notifications\Services\NoticeTypes;
use Kern\Platform\Crypto\BlindIndex\BlindIndexReindexers;
use Kern\Platform\Logging\Redactions;
use Kern\Platform\Routing\ConfiguredRateLimit;
use Kern\Platform\Routing\PackageRoutes;
use Kern\TaxRomania\Console\Commands\Banks\BackfillBanksCommand;
use Kern\TaxRomania\Console\Commands\Banks\ImportBanksCommand;
use Kern\TaxRomania\Console\Commands\Banks\RefreshBankLogosCommand;
use Kern\TaxRomania\Console\Commands\Fx\BackfillBnrYearCommand;
use Kern\TaxRomania\Console\Commands\Fx\ImportBnrDailyRatesCommand;
use Kern\TaxRomania\Console\Commands\Spv\CloseExpiredSpvSessionsCommand;
use Kern\TaxRomania\Console\Commands\TaxEngine\RegisterTaxRulesCommand;
use Kern\TaxRomania\Console\Commands\Wallet\BackfillCounterpartiesCommand;
use Kern\TaxRomania\Console\Commands\Wallet\ClassifyCounterpartiesCommand;
use Kern\TaxRomania\Console\Commands\Wallet\IndexCounterpartiesCommand;
use Kern\TaxRomania\Console\Commands\Wallet\PostDepositInterestCommand;
use Kern\TaxRomania\Console\Commands\Wallet\PostHoldingPayoutsCommand;
use Kern\TaxRomania\Console\Commands\Wallet\RemindMaturingDepositsCommand;
use Kern\TaxRomania\Declarations\DeclarationsServiceProvider;
use Kern\TaxRomania\FiscalParameters\FiscalParametersServiceProvider;
use Kern\TaxRomania\FiscalParameters\Requirements\FiscalParameterReadiness;
use Kern\TaxRomania\Fx\FxServiceProvider;
use Kern\TaxRomania\Notices\AnnounceIssueOnOffer;
use Kern\TaxRomania\Notices\DepositMaturityNotice;
use Kern\TaxRomania\Notices\HoldingPayoutNotice;
use Kern\TaxRomania\Notices\IssueOnOfferNotice;
use Kern\TaxRomania\Notices\StatementImportNotice;
use Kern\TaxRomania\Notices\WithdrawIssueNotices;
use Kern\TaxRomania\Spv\SpvServiceProvider;
use Kern\TaxRomania\StatementImport\StatementImportServiceProvider;
use Kern\TaxRomania\Subjects\SubjectsServiceProvider;
use Kern\TaxRomania\TaxEngine\Contracts\LedgerIncomeProvider;
use Kern\TaxRomania\Wallet\Providers\EloquentLedgerIncomeProvider;
use Kern\TaxRomania\Wallet\Queries\HoldingsOfALaw;
use RuntimeException;

/**
 * Romanian tax for an individual: the taxpayer, what the law says a tax costs, and everything a
 * return is built from.
 *
 * One package rather than nine, and deliberately. `TaxEngine`, `Declarations`, `Fx`, `Banks`,
 * `Rental`, `Spv`, `Wallet` and `StatementImport` have essentially one consumer between them —
 * each other — and `StatementImport` depends on most of the rest precisely because it is where a
 * wallet and a document meet. Splitting further would buy version numbers and cross-repository test
 * matrices, and would buy no reuse: nobody wants Romanian rental accruals without Romanian income
 * tax.
 *
 * The modules keep their own providers; this one registers them, so requiring the package is enough.
 *
 * Two things it does not decide. The tenant column is `config('tenancy.column')` like everywhere
 * else — this package is the reason it is `tax_subject_id`, but the word still lives in the
 * installation's config, so `kern/documents` can be told the same thing. And its screens are not
 * here: the Inertia pages, the shell and its navigation belong to the application, which is what
 * makes replacing React with Blade a change to one directory.
 */
final class TaxRomaniaServiceProvider extends ServiceProvider
{
    /**
     * Config this package ships, merged under the application's own.
     *
     * `reference/*` are the lists that are facts about Romania rather than decisions about this
     * installation — counties, months, the legal-form words in a company name, the tax categories a
     * transaction may be labelled with. They are configuration so that a screen and a validator read
     * the same list, not so that anyone edits them.
     *
     * @var list<string>
     */
    private const array CONFIG = [
        'banks',
        'fiscal_parameters',
        'operations',
        'spv',
        'statement_import',
        'subjects',
        'wallet',
        'ai/statements',
        'reference/counties',
        'reference/countries',
        'reference/months',
        'reference/party_names',
        'reference/tax_categories',
        // What to do on a website, as instructions for `apps/browser-worker`. Configuration rather
        // than code, so a portal that changes its HTML is an edit here and nothing else.
        'playbooks/anaf_spv',
    ];

    public function register(): void
    {
        foreach (self::CONFIG as $file) {
            $this->mergeConfigFrom(__DIR__ . "/../config/{$file}.php", str_replace('/', '.', $file));
        }

        // The minimum-wage decisions, watched for on the portal by kern/legislation — added once every
        // provider has registered, so legislation's own configuration is there to add to.
        // Merged in whole: a watch's key has a dot in it, which `set()` would read as one level deeper.
        $this->app->booting(static function (Application $app): void {
            $config = $app->make('config');
            $config->set('legislation.watches.declared', [...$config->array('legislation.watches.declared', []), ...require __DIR__ . '/../config/watches.php']);

            // The legislation this package requires to operate (ADR 0027) — merged in whole for the
            // same reason as watches: a requirement's key has a dot in it, which `set()` would read
            // as one level deeper.
            $config->set('legislation.requirements.declared', [...$config->array('legislation.requirements.declared', []), ...require __DIR__ . '/../config/legislation_requirements.php']);
        });

        /*
         * The ledger behind the tax engine's income port.
         *
         * Bound here rather than in a Wallet provider because Wallet has none — and the direction is the one
         * ADR 0010 requires either way: `TaxEngine` states the abstraction, `Wallet` implements it, and the
         * engine never learns that transaction splits exist.
         */
        $this->app->bind(LedgerIncomeProvider::class, EloquentLedgerIncomeProvider::class);

        $this->app->register(SubjectsServiceProvider::class);
        $this->app->register(FiscalParametersServiceProvider::class);
        $this->app->register(FxServiceProvider::class);
        $this->app->register(DeclarationsServiceProvider::class);
        $this->app->register(SpvServiceProvider::class);
        $this->app->register(StatementImportServiceProvider::class);

        $this->registerBlindIndexReindexers();
    }

    /**
     * The blind-indexed columns of this package, contributed to `kern:keys:reindex-blind-indexes`.
     *
     * Bound under plain string keys because a reindexer is a configured instance of one platform
     * class, not a type of its own — there is nothing for another class to depend on. The list itself
     * is `BlindIndexColumns`, at the package root for the reason given there: the columns belong to
     * four modules and the list belongs to none of them.
     */
    private function registerBlindIndexReindexers(): void
    {
        $keys = [];

        foreach (BlindIndexColumns::definitions() as $key => $factory) {
            $this->app->bind($key, $factory);
            $keys[] = $key;
        }

        $this->app->tag($keys, BlindIndexReindexers::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        // The sentences this package's HTTP layer answers with. `tax-romania::wallet.*` and so on.
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'tax-romania');

        $this->declareWhatMustNeverBeLogged();
        $this->declareNotificationTypes();

        // Two tables here point at a paragraph of the law: the value read from it, and the citation a
        // tax rule rests on. Declared so `kern/legislation` refuses to delete a law either still needs,
        // without that package having to know either table exists.
        $dependents = $this->app->make(SectionDependents::class);
        $dependents->declare('fiscal_parameter_versions', 'source_section_id');
        $dependents->declare('tax_rule_sources', 'source_section_id');

        // A person's holding of a bond keeps the order the bond was read from; nobody holding one, the
        // order goes with its bonds and their notices.
        $this->app->make(DocumentDependents::class)->declare('holdings', fn (string $law): int => $this->app->make(HoldingsOfALaw::class)($law));

        // How a `parameters` need is decided (ADR 0027): resolved here, against this package's own
        // models, so `kern/legislation` owns the readiness question but never a fiscal value. The one
        // argument is the active year's values — the minimum wage approved for it.
        $this->app->make(NeedResolvers::class)->register('parameters', function (string $argument): NeedStatus {
            if ($argument !== 'active-year') {
                throw new RuntimeException("The legislation requirement need \"parameters\" was declared with \"{$argument}\"; the only argument is \"active-year\".");
            }

            $readiness = $this->app->make(FiscalParameterReadiness::class);

            // Approved for the active year: met, and the screen says so. Otherwise the detail is what
            // it is waiting on — a value proposed and awaiting approval, named so a person knows what
            // they are approving, or nothing proposed yet (still being read, or not published).
            if ($readiness->minimumWageApprovedForActiveYear()) {
                return new NeedStatus(true, ['state' => 'approved']);
            }

            $proposed = $readiness->proposedMinimumWageForActiveYear();

            return new NeedStatus(false, $proposed === null
                ? ['state' => 'absent', 'year' => $readiness->activeYear()]
                : ['state' => 'proposed', ...$proposed]);
        });

        PackageRoutes::load(__DIR__ . '/../routes/api.php');

        ConfiguredRateLimit::register('statement-import-model-per-subject', 'statement_import.rate_limits.model_per_subject', ConfiguredRateLimit::perTenant());

        $this->publishes(
            array_combine(
                array_map(static fn (string $f): string => __DIR__ . "/../config/{$f}.php", self::CONFIG),
                array_map(static fn (string $f): string => config_path("{$f}.php"), self::CONFIG),
            ),
            'kern-tax-romania-config',
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportBanksCommand::class,
                BackfillBanksCommand::class,
                RefreshBankLogosCommand::class,
                ImportBnrDailyRatesCommand::class,
                BackfillBnrYearCommand::class,
                BackfillCounterpartiesCommand::class,
                ClassifyCounterpartiesCommand::class,
                CloseExpiredSpvSessionsCommand::class,
                RegisterTaxRulesCommand::class,
                IndexCounterpartiesCommand::class,
                PostDepositInterestCommand::class,
                PostHoldingPayoutsCommand::class,
                RemindMaturingDepositsCommand::class,
            ]);
        }
    }

    /**
     * The kinds of notice this package sends, for the bell, the devices and the switches on the
     * account page. Each is written once, in `Notices\`; nothing else names them.
     */
    private function declareNotificationTypes(): void
    {
        $types = $this->app->make(NoticeTypes::class);
        $types->register(StatementImportNotice::class);
        $types->register(DepositMaturityNotice::class);
        $types->register(HoldingPayoutNotice::class);
        $types->register(IssueOnOfferNotice::class);

        // `kern/investments` raises it when it first reads an issue still on offer; the notice is this package's (ADR 0025 §8).
        Event::listen(IssueOnOffer::class, [AnnounceIssueOnOffer::class, 'handle']);
        Event::listen(InstrumentsWithdrawn::class, [WithdrawIssueNotices::class, 'handle']);
    }

    /**
     * The identifiers only this package can recognise, told to the log redactor.
     *
     * `kern/platform` strips passwords, tokens, `Authorization` headers, JWTs, IBANs and OTP codes on
     * its own — shapes that mean the same thing in any application. A CNP is thirteen digits starting
     * 1-8, a CUI is a company's Romanian fiscal code, and "cod de siguranță" is what ANAF calls a
     * one-time code: none of that is knowable by a package that does not know what a taxpayer is.
     *
     * If this method is ever removed, `LogsNoRomanianIdentifierTest` in this package's own suite goes
     * red. That is the whole reason the test lives here rather than in the application: a declaration
     * nobody checks is a leak nobody notices.
     */
    private function declareWhatMustNeverBeLogged(): void
    {
        $redactions = $this->app->make(Redactions::class);

        $redactions->keys(
            'cnp',
            'cui',
            'cod_fiscal',
            'nr_reg_com',
            'nr_registru_comert',
            'fiscal_identifier',
            'cod_siguranta',
        );

        // A CNP: 13 digits whose first is 1-8, the sex-and-century marker.
        $redactions->pattern('/\b[1-8]\d{12}\b/');

        /*
         * ANAF's own phrasing for a one-time code, keeping the label so a log still says what was
         * asked for.
         *
         * `siguran[țt][ăa]` covers both spellings, and the second character class is the point: the
         * word is "siguranță", and the pattern this replaces — carried in the platform, written
         * `siguran[țt]a` — could only ever match "siguranta". It matched the correctly spelled word
         * never, and nothing noticed because the only test for that pattern used the English "OTP".
         */
        $redactions->replacement(
            '/\b(cod\s+de\s+siguran[țt][ăa])\s*[:=]?\s*\d{4,8}\b/iu',
            '$1: [REDACTED]',
        );
    }
}
