<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `security_events` (`BLUEPRINT.md` §6.1) — a lightweight, queryable log of security-relevant
 * occurrences (denied cross-tenant access, revoked-membership access attempts, and — once a later
 * task builds authentication — failed logins, session revocations, etc.).
 *
 * This is deliberately **not** the tamper-evident hash chain `App\Domain\Audit` owns
 * (`audit_events`/`audit_chain_heads`, docs/handoff/006-audit-chain.md): `security_events` is an
 * operational/monitoring log meant to be queried and alerted on directly (`BLUEPRINT.md` §23's "SPV
 * login failure by reason" class of metric), not a forensic ledger. A denied cross-tenant access
 * attempt is written here by `App\Domain\Subjects\Tenancy\TenantContext`; nothing in this task
 * also chains it through `AuditChainService` — recording the same event twice in two systems with
 * different retention/verification semantics was judged unnecessary duplication for this task's
 * scope (see docs/handoff/011-identity-tenancy.md).
 *
 * `context_json` carries structural identifiers only (resource type/id, reason codes) — never PII,
 * per `BLUEPRINT.md` §23. No foreign keys on `user_id`/`subject_id`: a security event must remain
 * even if the referenced row is later deleted (e.g. a since-deleted `TaxSubject`), so both are
 * nullable, unindexed-by-FK, `set null` is not applicable since there is no FK to cascade.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('event_type');
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('subject_id')->nullable()->index();

            $table->jsonb('context_json')->default('{}');

            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
