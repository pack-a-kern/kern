<?php

declare(strict_types=1);

use Deptrac\Deptrac\Contract\Config\Collector\DirectoryConfig;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Deptrac\Deptrac\Contract\Config\Layer;
use Deptrac\Deptrac\Contract\Config\Ruleset;

/*
 * Enforces blueprint §2.1 / docs/AGENTS.md §6: a module under packages/tax-romania/src/<Module>
 * never imports another module's Eloquent models directly. Cross-module traffic
 * goes through DTOs, contracts/interfaces, application services, or domain events.
 *
 * Only the modules with real code as of this milestone are declared as layers.
 * Add a module's layer here in the same change that adds its first class under
 * packages/tax-romania/src/<Module> — an undeclared module is invisible to this ruleset.
 */
return static function (DeptracConfig $config): void {

    /*
     * The audit chain, the security spine and the document pipeline are not layers here any more.
     *
     * They are `kern/audit`, `kern/platform` and `kern/documents`, required like any other package,
     * so deptrac — which collects this application's own directories — has nothing to say about
     * them, exactly as it has nothing to say about illuminate/support. The boundaries they used to
     * describe are now enforced by Composer: a module that should not record audit events does not
     * get to, by not requiring the package.
     *
     * `app/Support` is gone with them. What was left in it was `Text\PartyName`, which compares two
     * written names and needed the platform's normaliser to do it, so that is where it lives.
     */
    $subjects = Layer::withName('Subjects')->collectors(
        DirectoryConfig::create('packages/tax-romania/src/Subjects/.*'),
    );

    $fiscalParameters = Layer::withName('FiscalParameters')->collectors(
        DirectoryConfig::create('packages/tax-romania/src/FiscalParameters/.*'),
    );

    $fx = Layer::withName('Fx')->collectors(
        DirectoryConfig::create('packages/tax-romania/src/Fx/.*'),
    );

    $banks = Layer::withName('Banks')->collectors(
        DirectoryConfig::create('packages/tax-romania/src/Banks/.*'),
    );

    $wallet = Layer::withName('Wallet')->collectors(
        DirectoryConfig::create('packages/tax-romania/src/Wallet/.*'),
    );

    $taxEngine = Layer::withName('TaxEngine')->collectors(
        DirectoryConfig::create('packages/tax-romania/src/TaxEngine/.*'),
    );

    $rental = Layer::withName('Rental')->collectors(
        DirectoryConfig::create('packages/tax-romania/src/Rental/.*'),
    );

    $declarations = Layer::withName('Declarations')->collectors(
        DirectoryConfig::create('packages/tax-romania/src/Declarations/.*'),
    );

    $spv = Layer::withName('Spv')->collectors(
        DirectoryConfig::create('packages/tax-romania/src/Spv/.*'),
    );

    $operations = Layer::withName('Operations')->collectors(
        DirectoryConfig::create('packages/tax-romania/src/Operations/.*'),
    );

    $statementImport = Layer::withName('StatementImport')->collectors(
        DirectoryConfig::create('packages/tax-romania/src/StatementImport/.*'),
    );

    $config
        // The modules live in `kern/tax-romania` now — `packages/` from the host, `/var/packages`
        // from a container rooted at `/var/www/html`, the same spelling phpstan.neon uses. The
        // application's own `app/` is four controllers and two middleware and has no modules to keep
        // apart; what it may reach is Composer's business, not this file's.
        ->paths('../../packages/tax-romania/src', '../../packages/tax-romania/database', '../../packages/tax-romania/routes')
        ->excludeFiles('#.*/tests/.*#')
        ->layers($subjects, $fiscalParameters, $fx, $banks, $wallet, $taxEngine, $rental, $declarations, $spv, $operations, $statementImport)
        ->rulesets(
            // Subjects -> Audit: Kern\TaxRomania\Subjects\Actions\RegisterPfSubject calls
            // AuditChainService::record() (docs/handoff/006-audit-chain.md's sanctioned
            // "application service" cross-module traffic shape) to record TaxSubjectRegistered.
            Ruleset::forLayer($subjects),
            // FiscalParameters -> TaxEngine: Kern\TaxRomania\FiscalParameters\Providers\
            // {EloquentTaxRuleVersionProvider,EloquentFiscalParameterProvider} implement
            // Kern\TaxRomania\TaxEngine\Contracts\{TaxRuleVersionProvider,FiscalParameterProvider} —
            // ports TaxEngine defines, expressed purely in TaxEngine's own DTOs/exceptions/enums,
            // that this module implements against its own Eloquent models
            // (docs/adr/0010-tax-engine-ports-and-adapters.md, superseding ADR 0009). The
            // dependency direction is inverted from ADR 0009: FiscalParameters depends on
            // TaxEngine's abstractions, never the reverse, so no TaxEngine grant exists below.
            //
            // Everything else this module reaches is a package, and therefore not a layer:
            // `kern/legislation` for the laws a value is read out of and the provisions it is
            // anchored to, `kern/documents` for the one text-extraction contract that takes the text
            // of ANAF's D212 structure files, `kern/platform` for which model reads them, and
            // `kern/audit` for recording every approval. Composer states those, not deptrac.
            Ruleset::forLayer($fiscalParameters)->accesses($taxEngine),
            // Fx reaches no layer. It fetches and stores BNR's XML through `kern/legislation` —
            // `SourceIngestionService` and the two source repositories, an official source being an
            // official source whether it publishes a law or an exchange rate — which is a package
            // requirement now rather than a grant.
            Ruleset::forLayer($fx),
            // Banks likewise: it downloads the ECB's and GLEIF's lists through
            // `kern/legislation`'s allowlisted fetcher and stores them the same way Fx stores BNR's
            // files. Wallet does not access Banks: a deposit stores only `bank_id`, and the
            // controllers that turn one into a name use Kern\TaxRomania\Banks\Queries\BankDirectory
            // from the HTTP layer.
            Ruleset::forLayer($banks),
            // Wallet -> Subjects: every Wallet model uses Kern\Platform\Tenancy\
            // ScopedToTenant (a trait, not an Eloquent model import) and
            // Kern\Platform\Tenancy\TenantContext (a scoped service) — exactly the reusable
            // mechanism docs/handoff/011-identity-tenancy.md built for every future business
            // module to adopt. Wallet -> Fx: Kern\TaxRomania\Wallet\Services\Fx\
            // DepositInterestFxAnnotator calls Kern\TaxRomania\Fx\Services\FxRateQuery, the sanctioned
            // cross-module entry point docs/handoff/014-source-registry-bnr.md documents — Wallet
            // never imports a Fx or Subjects Eloquent model directly.
            // Wallet -> Audit: `Kern\TaxRomania\Wallet\Services\WalletAudit` records every account and
            // transaction change in the subject's append-only hash chain, through
            // `AuditChainService` and `AuditEventInput` — the same application-service crossing
            // `Documents` already makes for extraction review, and for the same reason. A wallet
            // record is a figure a tax return is computed from; an edit nobody recorded is
            // indistinguishable from an edit that never happened. Wallet never touches an Audit
            // Eloquent model, only the service that owns the chain's invariants.
            Ruleset::forLayer($wallet)->accesses($subjects, $fx),
            // TaxEngine -> Subjects: Kern\TaxRomania\TaxEngine\Models\{TaxFact,TaxAssessment} use
            // Kern\Platform\Tenancy\ScopedToTenant (a trait, not a model import) and
            // Kern\TaxRomania\TaxEngine\Repositories\TaxFactRepository references
            // Kern\Platform\Tenancy\TenantScope — the same reusable tenancy mechanism
            // Wallet already adopts above.
            //
            // TaxEngine -> Fx: Kern\TaxRomania\TaxEngine\Services\FxConversionPolicy calls
            // Kern\TaxRomania\Fx\Services\FxRateQuery — the sanctioned application-service crossing
            // (docs/handoff/014-source-registry-bnr.md), never an Fx Eloquent model.
            //
            // TaxEngine reads no rule or parameter model at all. It previously read them directly
            // (docs/adr/0009-tax-engine-reads-tax-knowledge-models-directly.md) as a documented
            // exception; that exception is superseded
            // (docs/adr/0010-tax-engine-ports-and-adapters.md). TaxEngine defines
            // Kern\TaxRomania\TaxEngine\Contracts\{TaxRuleVersionProvider,FiscalParameterProvider} —
            // ports named only in its own DTOs/exceptions/enums — and FiscalParameters implements
            // them (see the grant above). The dependency direction is inverted, not merely hidden:
            // this layer's ruleset below carries no FiscalParameters grant, and deptrac enforces
            // that, not just code review.
            Ruleset::forLayer($taxEngine)->accesses($subjects, $fx),
            // Rental -> Subjects: every Rental model uses Kern\Platform\Tenancy\
            // ScopedToTenant/TenantScope, the same reusable tenancy mechanism every other
            // business module above adopts (docs/handoff/011-identity-tenancy.md).
            // Rental -> Audit: opening, changing, ending and deleting a rental each record an entry
            // through Kern\Audit\Services\AuditChainService — the same sanctioned
            // application-service crossing Wallet and Documents make for the same chain.
            // Rental never accesses Wallet, Documents, or TaxEngine. A contract's income is what the
            // contract owes (Codul fiscal art. 84), computed from its own terms; no transaction is
            // read to establish it. `rental_contracts.source_document_id` is a foreign key at the
            // schema level only, and rental income reaches `Domain/TaxEngine` only through
            // Kern\TaxRomania\Rental\Events\RentalAccrualRecorded, a plain event class.
            Ruleset::forLayer($rental)->accesses($subjects),
            // Declarations -> Subjects: Kern\TaxRomania\Declarations\Models\Declaration uses
            // Kern\Platform\Tenancy\ScopedToTenant, the same reusable tenancy mechanism
            // every other business module above adopts (docs/handoff/011-identity-tenancy.md).
            // Declarations never accesses TaxEngine or Documents: Kern\TaxRomania\Declarations\
            // Repositories\TaxAssessmentSnapshotReader reads Domain/TaxEngine's `tax_assessments`
            // table via the plain query builder (never `Kern\TaxRomania\TaxEngine\Models\
            // TaxAssessment`) — the identical sanctioned shape `Kern\Documents\Queries\ResolveTransactionImportState` already establishes in this codebase for
            // `financial_transactions` (see docs/handoff/028-d212-declarations.md "Key
            // decisions"). `declarations.assessment_id -> tax_assessments` and
            // `declaration_submissions.receipt_document_id -> documents` are real foreign keys at
            // the migration/schema level only, the same precedent
            // `rental_contracts.source_document_id -> documents` already establishes.
            Ruleset::forLayer($declarations)->accesses($subjects),
            // Spv -> Identity: Kern\TaxRomania\Spv\Actions\{RegisterSpvConnection,InitiateSpvAuthAttempt}
            // call Kern\Platform\Identity\Support\SpvMfaPolicy::ensureStrongAuthentication() and take
            // an Kern\Platform\Identity\Models\User parameter — the enforceable MFA-before-SPV gate
            // docs/handoff/017-authentication.md built for exactly this milestone. Spv -> Audit:
            // every Spv action records an AuditChainService::record() event, the same sanctioned
            // application-service crossing every other module above uses. Spv -> Subjects: every
            // Spv model uses Kern\Platform\Tenancy\ScopedToTenant (a trait, not a model
            // import), the same reusable tenancy mechanism every other business module adopts.
            // `spv_messages.document_id -> documents` is a real foreign key at the migration/
            // schema level only, the same precedent `rental_contracts.source_document_id ->
            // documents` already establishes — no Documents grant is needed here.
            Ruleset::forLayer($spv)->accesses($subjects),
            Ruleset::forLayer($operations),
            // StatementImport -> Wallet, Documents: this module is the one place the two meet, and it
            // exists precisely so neither has to know about the other. A statement is stored as a
            // document (`Kern\Documents\Actions\InitiateDocumentUpload`, read back through
            // the object store), and its reviewed rows become transactions through
            // `Kern\TaxRomania\Wallet\Actions\RecordImportedTransactions` — application services and
            // DTOs, the sanctioned cross-module shape (`docs/AGENTS.md` §6), with the same
            // dependency direction `Operations -> Documents` already establishes.
            //
            // Wallet and Documents gain nothing in return: neither imports anything from here, and
            // nothing outside HTTP depends on this layer.
            // StatementImport -> Identity: Kern\TaxRomania\StatementImport\Services\NotifyImportOwner finds
            // the user who started an import, to tell them it moved on. Nothing else here reads Identity.
            // StatementImport -> Banks: Kern\TaxRomania\StatementImport\Services\GuessStatementBank matches the
            // statement's IBAN, or the bank the model named, against the bank directory.
            Ruleset::forLayer($statementImport)->accesses($subjects, $wallet, $banks),
        );
};
