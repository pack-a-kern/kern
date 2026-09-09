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

    $config
        ->paths('app', 'database', 'routes')
        ->excludeFiles('#.*/tests/.*#')
        ->layers($support, $identity, $subjects, $audit, $taxKnowledge, $fx, $wallet, $taxEngine, $documents, $rental, $declarations, $spv, $settings, $operations)
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
            Ruleset::forLayer($identity)->accesses($support, $audit),
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
            Ruleset::forLayer($taxKnowledge)->accesses($support, $taxEngine),
            // Fx -> TaxKnowledge: App\Domain\Fx\Actions\{ImportBnrDailyRates,BackfillBnrYear} call
            // App\Domain\TaxKnowledge\Services\SourceIngestionService (fetch/store/version the raw
            // BNR XML) and App\Domain\Fx\Repositories\FxSourceRepository /
            // App\Domain\Fx\Services\FxRateQuery call
            // App\Domain\TaxKnowledge\Repositories\{OfficialSourceRepository,
            // SourceDocumentVersionRepository} — both are the sanctioned "application service" /
            // DTO cross-module shape (docs/AGENTS.md §6); Fx never imports a TaxKnowledge model.
            Ruleset::forLayer($fx)->accesses($support, $taxKnowledge),
            // Wallet -> Subjects: every Wallet model uses App\Domain\Subjects\Tenancy\
            // ScopedToTaxSubject (a trait, not an Eloquent model import) and
            // App\Domain\Subjects\Tenancy\TenantContext (a scoped service) — exactly the reusable
            // mechanism docs/handoff/011-identity-tenancy.md built for every future business
            // module to adopt. Wallet -> Fx: App\Domain\Wallet\Services\Fx\
            // DepositInterestFxAnnotator calls App\Domain\Fx\Services\FxRateQuery, the sanctioned
            // cross-module entry point docs/handoff/014-source-registry-bnr.md documents — Wallet
            // never imports a Fx or Subjects Eloquent model directly.
            Ruleset::forLayer($wallet)->accesses($support, $subjects, $fx),
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
            // business module above adopts (docs/handoff/011-identity-tenancy.md). Rental never
            // accesses Wallet, Documents, or TaxEngine: App\Domain\Rental\Services\Matching\
            // TransactionMatchCandidateFinder reads Wallet's `financial_transactions` table via
            // the plain query builder (never `App\Domain\Wallet\Models\FinancialTransaction`),
            // `rental_contracts`/`rental_contract_versions`.source_document_id and
            // `rental_payments.transaction_id` are real foreign keys at the migration/schema
            // level only — the identical "a migration may reference another module's table
            // without the application importing its Eloquent model" precedent
            // `deposit_interest_events.fx_source_document_version_id` already establishes in this
            // codebase — and rental income is surfaced to `Domain/TaxEngine` only via
            // `App\Domain\Rental\Events\RentalAccrualRecorded`, a plain event class with no
            // dependency in the other direction. See docs/handoff/025-rental-module.md.
            Ruleset::forLayer($rental)->accesses($support, $subjects),
            // Declarations -> Subjects: App\Domain\Declarations\Models\Declaration uses
            // App\Domain\Subjects\Tenancy\ScopedToTaxSubject, the same reusable tenancy mechanism
            // every other business module above adopts (docs/handoff/011-identity-tenancy.md).
            // Declarations never accesses TaxEngine or Documents: App\Domain\Declarations\
            // Repositories\TaxAssessmentSnapshotReader reads Domain/TaxEngine's `tax_assessments`
            // table via the plain query builder (never `App\Domain\TaxEngine\Models\
            // TaxAssessment`) — the identical sanctioned shape `App\Domain\Rental\Services\
            // Matching\TransactionMatchCandidateFinder` already establishes in this codebase for
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
            // Operations accesses no other module. `fx_rates`, `source_documents`,
            // `source_sections`, `source_section_chunks`, `documents` and `official_sources` are
            // all read through the plain query builder — the identical crossing
            // App\Domain\Rental\Services\Matching\TransactionMatchCandidateFinder already
            // establishes for `financial_transactions`. In particular there is no Settings grant:
            // App\Domain\Settings\Services\ProbeSystemHealth is composed into the operations
            // *overview response* by the HTTP controller, which belongs to no layer, not by
            // anything under app/Domain/Operations.
            Ruleset::forLayer($operations)->accesses($support, $audit, $documents),
        );
};
