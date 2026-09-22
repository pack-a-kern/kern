<?php

declare(strict_types=1);

use Deptrac\Deptrac\Contract\Config\Collector\DirectoryConfig;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Deptrac\Deptrac\Contract\Config\Layer;
use Deptrac\Deptrac\Contract\Config\Ruleset;

/*
 * Enforces blueprint §2.1 / docs/AGENTS.md §6: a module under app/Domain/<Module>
 * never imports another module's Eloquent models directly. Cross-module traffic
 * goes through DTOs, contracts/interfaces, application services, or domain events.
 *
 * Only the modules with real code as of this milestone are declared as layers.
 * Add a module's layer here in the same change that adds its first class under
 * app/Domain/<Module> — an undeclared module is invisible to this ruleset.
 */
return static function (DeptracConfig $config): void {
    $support = Layer::withName('Support')->collectors(
        DirectoryConfig::create('app/Support/.*'),
    );

    $identity = Layer::withName('Identity')->collectors(
        DirectoryConfig::create('app/Domain/Identity/.*'),
    );

    $subjects = Layer::withName('Subjects')->collectors(
        DirectoryConfig::create('app/Domain/Subjects/.*'),
    );

    $audit = Layer::withName('Audit')->collectors(
        DirectoryConfig::create('app/Domain/Audit/.*'),
    );

    $taxKnowledge = Layer::withName('TaxKnowledge')->collectors(
        DirectoryConfig::create('app/Domain/TaxKnowledge/.*'),
    );

    $fx = Layer::withName('Fx')->collectors(
        DirectoryConfig::create('app/Domain/Fx/.*'),
    );

    $banks = Layer::withName('Banks')->collectors(
        DirectoryConfig::create('app/Domain/Banks/.*'),
    );

    $wallet = Layer::withName('Wallet')->collectors(
        DirectoryConfig::create('app/Domain/Wallet/.*'),
    );

    $taxEngine = Layer::withName('TaxEngine')->collectors(
        DirectoryConfig::create('app/Domain/TaxEngine/.*'),
    );

    $documents = Layer::withName('Documents')->collectors(
        DirectoryConfig::create('app/Domain/Documents/.*'),
    );

    $rental = Layer::withName('Rental')->collectors(
        DirectoryConfig::create('app/Domain/Rental/.*'),
    );

    $declarations = Layer::withName('Declarations')->collectors(
        DirectoryConfig::create('app/Domain/Declarations/.*'),
    );

    $spv = Layer::withName('Spv')->collectors(
        DirectoryConfig::create('app/Domain/Spv/.*'),
    );

    $settings = Layer::withName('Settings')->collectors(
        DirectoryConfig::create('app/Domain/Settings/.*'),
    );

    $operations = Layer::withName('Operations')->collectors(
        DirectoryConfig::create('app/Domain/Operations/.*'),
    );

    $statementImport = Layer::withName('StatementImport')->collectors(
        DirectoryConfig::create('app/Domain/StatementImport/.*'),
    );

    $config
        ->paths('app', 'database', 'routes')
        ->excludeFiles('#.*/tests/.*#')
        ->layers($support, $identity, $subjects, $audit, $taxKnowledge, $fx, $banks, $wallet, $taxEngine, $documents, $rental, $declarations, $spv, $settings, $operations, $statementImport)
        ->rulesets(
            Ruleset::forLayer($support),
            // Identity -> Audit: this module's login/MFA/session/passkey actions
            // (App\Domain\Identity\Actions\*) record authentication events via
            // AuditChainService::record() — the same sanctioned "application service"
            // cross-module traffic shape docs/handoff/006-audit-chain.md documents for Subjects.
            // Identity never imports App\Domain\Subjects\Models\SecurityEvent directly (that
            // table's rows are written via a plain DB::table('security_events')->insert(...) call
            // per docs/handoff/011-identity-tenancy.md "Key decisions" #9), so no Identity ->
            // Subjects grant is needed here.
            // Identity -> Settings: App\Domain\Identity\Services\RegistrationPolicy reads
            // SettingKey::IDENTITY_REGISTRATION_ENABLED through SettingsRepository — the same
            // application-service crossing Documents already makes for the AI provider settings,
            // and for the same reason: whether sign-ups are open is an operational switch an owner
            // flips at runtime, so the module that registers users has to ask rather than assume.
            // Identity never touches a Settings Eloquent model.
            Ruleset::forLayer($identity)->accesses($support, $audit, $settings),
            // Subjects -> Audit: App\Domain\Subjects\Actions\RegisterPfSubject calls
            // AuditChainService::record() (docs/handoff/006-audit-chain.md's sanctioned
            // "application service" cross-module traffic shape) to record TaxSubjectRegistered.
            Ruleset::forLayer($subjects)->accesses($support, $audit),
            Ruleset::forLayer($audit)->accesses($support),
            // TaxKnowledge -> TaxEngine: App\Domain\TaxKnowledge\Providers\
            // {EloquentTaxRuleVersionProvider,EloquentFiscalParameterProvider} implement
            // App\Domain\TaxEngine\Contracts\{TaxRuleVersionProvider,FiscalParameterProvider} —
            // ports TaxEngine defines, expressed purely in TaxEngine's own DTOs/exceptions/enums,
            // that this module implements against its own Eloquent models
            // (docs/adr/0010-tax-engine-ports-and-adapters.md, superseding ADR 0009). This is the
            // sanctioned "contracts/interfaces + DTOs" cross-module shape docs/AGENTS.md §6
            // requires, just with the dependency direction inverted from ADR 0009: TaxKnowledge
            // depends on TaxEngine's abstractions, never the reverse, so no
            // TaxEngine -> TaxKnowledge grant exists below.
            //
            // TaxKnowledge -> Documents: exactly one contract, App\Domain\Documents\Extraction\
            // TextExtractionGateway (and its result DTOs and exceptions), which
            // App\Domain\TaxKnowledge\FiscalParameters\Forms\FormStructureSource calls to take the text
            // of ANAF's D212 structure files — the same PDF service documents use, rather than a
            // second client for it. TaxKnowledge never touches a document, and Documents never
            // imports anything from here.
            Ruleset::forLayer($taxKnowledge)->accesses($support, $taxEngine, $documents),
            // Fx -> TaxKnowledge: App\Domain\Fx\Actions\{ImportBnrDailyRates,BackfillBnrYear} call
            // App\Domain\TaxKnowledge\Services\SourceIngestionService (fetch/store/version the raw
            // BNR XML) and App\Domain\Fx\Services\FxRateQuery and the BNR actions call
            // App\Domain\TaxKnowledge\Repositories\{OfficialSourceRepository,
            // SourceDocumentVersionRepository} — both are the sanctioned "application service" /
            // DTO cross-module shape (docs/AGENTS.md §6); Fx never imports a TaxKnowledge model.
            Ruleset::forLayer($fx)->accesses($support, $taxKnowledge),
            // Banks -> TaxKnowledge: App\Domain\Banks\Actions\ImportBanks downloads through
            // App\Domain\TaxKnowledge\Fetching\AllowlistedHttpFetcher and stores each file through
            // App\Domain\TaxKnowledge\Services\SourceIngestionService — the same application-service
            // crossing Fx makes for BNR's files. Banks never imports a TaxKnowledge model. Wallet does
            // not access Banks: a deposit stores only `bank_id`, and the controllers that turn a bank
            // into a name use App\Domain\Banks\Queries\BankDirectory from the HTTP layer.
            Ruleset::forLayer($banks)->accesses($support, $taxKnowledge),
            // Wallet -> Subjects: every Wallet model uses App\Domain\Subjects\Tenancy\
            // ScopedToTaxSubject (a trait, not an Eloquent model import) and
            // App\Domain\Subjects\Tenancy\TenantContext (a scoped service) — exactly the reusable
            // mechanism docs/handoff/011-identity-tenancy.md built for every future business
            // module to adopt. Wallet -> Fx: App\Domain\Wallet\Services\Fx\
            // DepositInterestFxAnnotator calls App\Domain\Fx\Services\FxRateQuery, the sanctioned
            // cross-module entry point docs/handoff/014-source-registry-bnr.md documents — Wallet
            // never imports a Fx or Subjects Eloquent model directly.
            // Wallet -> Audit: `App\Domain\Wallet\Services\WalletAudit` records every account and
            // transaction change in the subject's append-only hash chain, through
            // `AuditChainService` and `AuditEventInput` — the same application-service crossing
            // `Documents` already makes for extraction review, and for the same reason. A wallet
            // record is a figure a tax return is computed from; an edit nobody recorded is
            // indistinguishable from an edit that never happened. Wallet never touches an Audit
            // Eloquent model, only the service that owns the chain's invariants.
            Ruleset::forLayer($wallet)->accesses($support, $subjects, $fx, $audit),
            // TaxEngine -> Subjects: App\Domain\TaxEngine\Models\{TaxFact,TaxAssessment} use
            // App\Domain\Subjects\Tenancy\ScopedToTaxSubject (a trait, not a model import) and
            // App\Domain\TaxEngine\Repositories\TaxFactRepository references
            // App\Domain\Subjects\Tenancy\TaxSubjectScope — the same reusable tenancy mechanism
            // Wallet already adopts above.
            //
            // TaxEngine -> Fx: App\Domain\TaxEngine\Services\FxConversionPolicy calls
            // App\Domain\Fx\Services\FxRateQuery — the sanctioned application-service crossing
            // (docs/handoff/014-source-registry-bnr.md), never an Fx Eloquent model.
            //
            // TaxEngine no longer accesses TaxKnowledge at all. It previously read
            // App\Domain\TaxKnowledge\Models\{TaxRule,TaxRuleVersion,TaxRuleSource,
            // FiscalParameter,FiscalParameterVersion} directly (docs/adr/0009-tax-engine-reads-
            // tax-knowledge-models-directly.md) as a documented exception; that exception is now
            // superseded (docs/adr/0010-tax-engine-ports-and-adapters.md). TaxEngine defines
            // App\Domain\TaxEngine\Contracts\{TaxRuleVersionProvider,FiscalParameterProvider} —
            // ports named only in its own DTOs/exceptions/enums — and TaxKnowledge implements
            // them (see the TaxKnowledge -> TaxEngine grant above). The dependency direction is
            // inverted, not merely hidden: this layer's ruleset below carries no TaxKnowledge
            // grant, and deptrac enforces that, not just code review.
            Ruleset::forLayer($taxEngine)->accesses($support, $subjects, $fx),
            // Documents -> Subjects: every Documents model/job uses App\Domain\Subjects\Tenancy\
            // ScopedToTaxSubject/TaxSubjectScope/TenantContext, the same reusable tenancy
            // mechanism Wallet/TaxEngine already adopt above (docs/handoff/011-identity-tenancy.md).
            // Documents -> Audit: App\Domain\Documents\ExternalAi\RequestExternalDocumentExtraction
            // calls AuditChainService::record() to write the "external send completed" audit event
            // ADR 0005 requires — the same sanctioned application-service crossing every other
            // module above uses for the same audit chain.
            // Documents -> Settings: App\Domain\Documents\ExternalAi\RequestExternalDocumentExtraction
            // calls App\Domain\Settings\Services\ResolveAiProviderCredentials to learn the
            // current AI provider configuration (docs/handoff/041-settings-module.md) — the same
            // sanctioned "application service" cross-module shape every grant above uses, plus
            // App\Domain\Settings\DataTransferObjects\AiProviderCredentials, a plain DTO passed
            // as a constructor argument (never a Settings Eloquent model — this module has none).
            Ruleset::forLayer($documents)->accesses($support, $subjects, $audit, $settings),
            // Rental -> Subjects: every Rental model uses App\Domain\Subjects\Tenancy\
            // ScopedToTaxSubject/TaxSubjectScope, the same reusable tenancy mechanism every other
            // business module above adopts (docs/handoff/011-identity-tenancy.md).
            // Rental -> Audit: opening, changing, ending and deleting a rental each record an entry
            // through App\Domain\Audit\Services\AuditChainService — the same sanctioned
            // application-service crossing Wallet and Documents make for the same chain.
            // Rental never accesses Wallet, Documents, or TaxEngine. A contract's income is what the
            // contract owes (Codul fiscal art. 84), computed from its own terms; no transaction is
            // read to establish it. `rental_contracts.source_document_id` is a foreign key at the
            // schema level only, and rental income reaches `Domain/TaxEngine` only through
            // App\Domain\Rental\Events\RentalAccrualRecorded, a plain event class.
            Ruleset::forLayer($rental)->accesses($support, $subjects, $audit),
            // Declarations -> Subjects: App\Domain\Declarations\Models\Declaration uses
            // App\Domain\Subjects\Tenancy\ScopedToTaxSubject, the same reusable tenancy mechanism
            // every other business module above adopts (docs/handoff/011-identity-tenancy.md).
            // Declarations never accesses TaxEngine or Documents: App\Domain\Declarations\
            // Repositories\TaxAssessmentSnapshotReader reads Domain/TaxEngine's `tax_assessments`
            // table via the plain query builder (never `App\Domain\TaxEngine\Models\
            // TaxAssessment`) — the identical sanctioned shape `App\Domain\Documents\Queries\ResolveTransactionImportState` already establishes in this codebase for
            // `financial_transactions` (see docs/handoff/028-d212-declarations.md "Key
            // decisions"). `declarations.assessment_id -> tax_assessments` and
            // `declaration_submissions.receipt_document_id -> documents` are real foreign keys at
            // the migration/schema level only, the same precedent
            // `rental_contracts.source_document_id -> documents` already establishes.
            Ruleset::forLayer($declarations)->accesses($support, $subjects),
            // Spv -> Identity: App\Domain\Spv\Actions\{RegisterSpvConnection,InitiateSpvAuthAttempt}
            // call App\Domain\Identity\Support\SpvMfaPolicy::ensureStrongAuthentication() and take
            // an App\Domain\Identity\Models\User parameter — the enforceable MFA-before-SPV gate
            // docs/handoff/017-authentication.md built for exactly this milestone. Spv -> Audit:
            // every Spv action records an AuditChainService::record() event, the same sanctioned
            // application-service crossing every other module above uses. Spv -> Subjects: every
            // Spv model uses App\Domain\Subjects\Tenancy\ScopedToTaxSubject (a trait, not a model
            // import), the same reusable tenancy mechanism every other business module adopts.
            // `spv_messages.document_id -> documents` is a real foreign key at the migration/
            // schema level only, the same precedent `rental_contracts.source_document_id ->
            // documents` already establishes — no Documents grant is needed here.
            Ruleset::forLayer($spv)->accesses($support, $subjects, $identity, $audit),
            // Settings -> Support: App\Domain\Settings\Support\SystemSecretCipher uses
            // App\Support\Crypto\{EnvelopeCipher,AssociatedData} and
            // App\Support\KeyManagement\{SubjectDekService,Contracts\KeyManagementService}
            // directly — this module's own encryption scope, not a TaxSubject's (see
            // App\Domain\Settings\Support\SystemEncryptionScope). Settings -> Audit:
            // App\Domain\Settings\Services\AiProviderSettingsService calls
            // AuditChainService::record() for every settings change, the same sanctioned
            // application-service crossing every other module above uses. Settings never
            // accesses Subjects/Identity/Documents: it has no TaxSubject scoping (these are
            // system-wide, not per-subject, settings) and no Eloquent model of its own to import.
            Ruleset::forLayer($settings)->accesses($support, $audit),
            // Operations -> Audit: every act on the operations console (starting a task, pausing
            // the schedule, retrying or discarding a failed job, disabling an official source)
            // records an AuditChainService::record() event, the same sanctioned application-service
            // crossing every module above uses.
            //
            // Operations -> Documents: exactly one symbol,
            // App\Domain\Documents\Enums\DocumentStatus, read by
            // App\Domain\Operations\Services\ProbeDataFreshness to count documents left in a
            // non-terminal state. A backed enum is a value type, the same "DTOs, contracts/
            // interfaces" shape docs/AGENTS.md §6 sanctions — never
            // App\Domain\Documents\Models\Document. The alternative was restating 'READY' and
            // 'REJECTED' as string literals in this module, which would silently stop matching the
            // day that lifecycle gains a state.
            //
            // Operations accesses no other module. `ext_fx_rates`, `ext_source_documents`,
            // `ext_source_sections`, `ext_source_section_chunks`, `documents` and `ext_official_sources` are
            // all read through the plain query builder — the identical crossing
            // `App\Domain\Documents\Queries\ResolveTransactionImportState` already
            // establishes for `financial_transactions`. In particular there is no Settings grant:
            // App\Domain\Settings\Services\ProbeSystemHealth is composed into the operations
            // *overview response* by the HTTP controller, which belongs to no layer, not by
            // anything under app/Domain/Operations.
            Ruleset::forLayer($operations)->accesses($support, $audit, $documents),
            // StatementImport -> Wallet, Documents: this module is the one place the two meet, and it
            // exists precisely so neither has to know about the other. A statement is stored as a
            // document (`App\Domain\Documents\Actions\InitiateDocumentUpload`, read back through
            // the object store), and its reviewed rows become transactions through
            // `App\Domain\Wallet\Actions\RecordImportedTransactions` — application services and
            // DTOs, the sanctioned cross-module shape (`docs/AGENTS.md` §6), with the same
            // dependency direction `Operations -> Documents` already establishes.
            //
            // Wallet and Documents gain nothing in return: neither imports anything from here, and
            // nothing outside HTTP depends on this layer.
            // StatementImport -> Identity: App\Domain\StatementImport\Services\NotifyImportOwner finds
            // the user who started an import, to tell them it moved on. Nothing else here reads Identity.
            // StatementImport -> Banks: App\Domain\StatementImport\Services\GuessStatementBank matches the
            // statement's IBAN, or the bank the model named, against the bank directory.
            Ruleset::forLayer($statementImport)->accesses($support, $identity, $subjects, $audit, $settings, $wallet, $documents, $banks),
        );
};
