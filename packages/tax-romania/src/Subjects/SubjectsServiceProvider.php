<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects;

use Illuminate\Support\ServiceProvider;
use Kern\Documents\Database\Factories\DocumentFactory;
use Kern\Platform\KeyManagement\Contracts\SubjectDekRepository;
use Kern\Platform\KeyManagement\DekRewrapService;
use Kern\Platform\Tenancy\Contracts\TenantGate;
use Kern\Platform\Tenancy\Contracts\TenantMemberships;
use Kern\Platform\Tenancy\Contracts\TenantNames;
use Kern\TaxRomania\Subjects\Infrastructure\EloquentSubjectDekRepository;
use Kern\TaxRomania\Subjects\Listeners\AnnounceTaxpayerChanges;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Subjects\Tenancy\MembershipTenantGate;
use Kern\TaxRomania\Subjects\Tenancy\SubjectMemberships;
use Kern\TaxRomania\Subjects\Tenancy\SubjectNames;

/**
 * The answers `kern/platform` cannot give itself.
 *
 * The platform encrypts every row under a per-tenant data key, restricts every read to the tenant
 * now bound, and recognises a tenant's name on a document — but it does not know where that key is
 * kept, who is allowed to act for whom, who holds which membership, or what a tenant is called.
 * Those are this product's answers: the key is a column on `tax_subjects`, permission is a
 * non-revoked `subject_memberships` row, and the names are two encrypted columns. Bound here, so the platform stays a mechanism and
 * this module stays the only place a taxpayer is mentioned.
 */
final class SubjectsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SubjectDekRepository::class, EloquentSubjectDekRepository::class);

        // The same class again, contributed to the rewrap run: `tax_subjects` and `users` are this
        // module's tables, so this is the only place that can say they hold wrapped DEKs.
        $this->app->tag([EloquentSubjectDekRepository::class], DekRewrapService::TAG);
        $this->app->bind(TenantGate::class, MembershipTenantGate::class);
        $this->app->bind(TenantNames::class, SubjectNames::class);
        $this->app->bind(TenantMemberships::class, SubjectMemberships::class);

        // Reading an identity card into a new taxpayer (ADR 0028). The provider is shared
        // (`ai.providers`); only this read's model, prompts and limits live here.
        $this->mergeConfigFrom(__DIR__ . '/../../config/ai/idcard.php', 'ai.idcard');

        // A document belongs to a taxpayer here; `kern/documents` only knows it belongs to a subject.
        DocumentFactory::resolveSubjectUsing(static fn () => TaxSubject::factory());
    }

    public function boot(): void
    {
        // Every open frame of the people a taxpayer belongs to redraws its switcher (TaxpayersChanged).
        TaxSubject::saved(AnnounceTaxpayerChanges::subjectSaved(...));
        TaxSubject::deleted(AnnounceTaxpayerChanges::subjectDeleted(...));
        SubjectMembership::saved(AnnounceTaxpayerChanges::membershipChanged(...));
        SubjectMembership::deleted(AnnounceTaxpayerChanges::membershipChanged(...));
    }
}
