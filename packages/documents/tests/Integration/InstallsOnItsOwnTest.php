<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Kern\Documents\Models\Document;
use Kern\Documents\Payments\ResolvePaymentParties;
use Kern\Platform\Tenancy\Tenancy;
use Kern\Platform\Tenancy\TenantContext;
use Kern\Platform\Testing\ArrayTenantNames;

/**
 * Documents with a workspace for a tenant and no taxpayer anywhere.
 */
it('creates its tables with the tenant column the configuration names', function (): void {
    foreach (['documents', 'document_parts', 'document_derivatives', 'document_extractions',
        'document_scans', 'document_ai_consents'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing {$table}");
    }

    expect(Schema::hasColumn('documents', 'workspace_id'))->toBeTrue()
        ->and(Schema::hasColumn('documents', 'tax_subject_id'))->toBeFalse()
        // `document_ai_consents` is keyed *by* the tenant, so its primary key is renamed too.
        ->and(Schema::hasColumn('document_ai_consents', 'workspace_id'))->toBeTrue()
        // And with no owner table configured, no foreign key was invented for one.
        ->and(Schema::hasTable('tax_subjects'))->toBeFalse();
});

it('encrypts a document\'s name under the tenant\'s own key, whatever the tenant is called', function (): void {
    $workspace = (string) Str::uuid7();
    app(TenantContext::class)->authorizeAndActivate((string) Str::uuid7(), $workspace);

    $document = Document::create([
        // Named explicitly, and it has to be: the `Encrypted` cast needs the tenant's key while the
        // attribute is being assigned, which is inside `fill()` — before the `creating` hook that
        // stamps the column for models with nothing encrypted on them. `ScopedToTenant` says so, and
        // this is what saying so looks like at a call site.
        Tenancy::column() => $workspace,
        'status' => 'QUARANTINE',
        'source' => 'USER_UPLOAD',
        'sha256' => str_repeat('a', 64),
        'part_count' => 1,
        'received_at' => now('UTC'),
        'display_name_ciphertext' => 'factura-martie.pdf',
    ]);

    $row = DB::table('documents')->where('id', $document->id)->first();

    expect($document->tenantId())->toBe($workspace)
        ->and($row?->workspace_id)->toBe($workspace)
        ->and((string) $row?->display_name_ciphertext)->not->toContain('factura')
        ->and(Document::query()->sole()->display_name_ciphertext)->toBe('factura-martie.pdf');
});

it('decides which side of a payment the tenant is on, from the names the application gives it', function (): void {
    $workspace = (string) Str::uuid7();
    ArrayTenantNames::$names = [$workspace => ['SENDWISE SRL', 'RO37266479']];

    $parties = app(ResolvePaymentParties::class)($workspace, [
        'payer' => 'S.C. SENDWISE S.R.L.',
        'beneficiary' => 'SANDU LAURENTIU-CONSTANTIN',
    ]);

    expect($parties['direction'])->toBe('OUT')
        ->and($parties['counterparty'])->toBe('SANDU LAURENTIU-CONSTANTIN');

    // The same document read for a tenant that is neither party says nothing, rather than guessing.
    ArrayTenantNames::$names = [$workspace => ['Altcineva SRL']];

    expect(app(ResolvePaymentParties::class)($workspace, [
        'payer' => 'S.C. SENDWISE S.R.L.',
        'beneficiary' => 'SANDU LAURENTIU-CONSTANTIN',
    ])['direction'])->toBeNull();
});
