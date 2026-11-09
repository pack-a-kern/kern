<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Subjects\Actions\RegisterPfSubject;
use Kern\TaxRomania\Subjects\DataTransferObjects\RegisterPfSubjectInput;
use Kern\TaxRomania\Subjects\Enums\FiscalIdentifierKind;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;

/**
 * The product, installed as six packages into an application that is otherwise empty.
 */
it('brings the whole schema, its own and every package it depends on', function (): void {
    foreach ([
        'tax_subjects', 'subject_memberships', 'pf_profiles',        // its own
        'financial_transactions', 'ext_fx_rates', 'declarations',
        'users', 'settings', 'encryption_key_records',               // the platform's
        'audit_events',                                              // the audit chain's
        'documents', 'document_parts',                               // documents'
        'ext_source_sections', 'ext_source_section_chunks',          // legislation's
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing {$table}");
    }
});

it('registers a taxpayer whose identifier is never stored in the clear', function (): void {
    $user = User::createWithEmail('contabil@example.ro');

    $subject = app(RegisterPfSubject::class)(new RegisterPfSubjectInput(
        registeringUserId: $user->id,
        registeringUserRoles: ['SUPER_ADMIN'],
        displayName: 'Ana Pop',
        firstName: 'Ana',
        lastName: 'Pop',
        cnp: '2960229123456',
        birthDate: '1996-02-29',
    ));

    $row = DB::table('tax_subjects')->where('id', $subject->id)->first();
    $profile = DB::table('pf_profiles')->where('tax_subject_id', $subject->id)->first();

    expect($subject->fiscal_identifier_kind)->toBe(FiscalIdentifierKind::CNP)
        // Not in the taxpayer's row, not in the profile's, not anywhere a dump would show it.
        ->and(json_encode($row, JSON_THROW_ON_ERROR))->not->toContain('2960229123456')
        ->and(json_encode($profile, JSON_THROW_ON_ERROR))->not->toContain('2960229123456')
        // And still findable, by the keyed hash of it rather than by decrypting every row.
        ->and($row?->fiscal_identifier_blind_index)->toBe(
            app(BlindIndexHasher::class)->compute(FiscalIdentifierKind::CNP->keyedValue('2960229123456')),
        );

    // The membership the registration created is what authorises acting for this taxpayer — the
    // answer `kern/platform` asks this package for, given here rather than configured.
    app(TenantContext::class)->authorizeAndActivate($user->id, $subject->id);

    expect(app(TenantContext::class)->currentTenantId())->toBe($subject->id);
});

it('scopes a taxpayer\'s own records to them', function (): void {
    $user = User::createWithEmail('contabil@example.ro');

    $mine = subjectFor($user, 'Ana Pop', '2960229123456');
    $theirs = subjectFor($user, 'Ion Ionescu', '1800101123456');

    app(TenantContext::class)->authorizeAndActivate($user->id, $theirs->id);
    FinancialAccount::create(['kind' => 'GENERAL', 'currency_code' => 'RON']);

    app(TenantContext::class)->authorizeAndActivate($user->id, $mine->id);
    FinancialAccount::create(['kind' => 'GENERAL', 'currency_code' => 'RON']);

    expect(FinancialAccount::query()->count())->toBe(1)
        ->and(FinancialAccount::query()->sole()->tax_subject_id)->toBe($mine->id);
});

function subjectFor(User $user, string $name, string $cnp): TaxSubject
{
    return app(RegisterPfSubject::class)(new RegisterPfSubjectInput(
        registeringUserId: $user->id,
        registeringUserRoles: ['SUPER_ADMIN'],
        displayName: $name,
        firstName: explode(' ', $name)[0],
        lastName: explode(' ', $name)[1],
        cnp: $cnp,
        birthDate: '1990-01-01',
    ));
}
