<?php

declare(strict_types=1);

namespace App\Domain\Subjects;

use App\Domain\Subjects\Infrastructure\EloquentSubjectDekRepository;
use App\Domain\Subjects\Tenancy\MembershipTenantGate;
use Illuminate\Support\ServiceProvider;
use Kern\Platform\KeyManagement\Contracts\SubjectDekRepository;
use Kern\Platform\Tenancy\Contracts\TenantGate;

/**
 * The two answers `kern/platform` cannot give itself.
 *
 * The platform encrypts every row under a per-tenant data key and restricts every read to the
 * tenant now bound — but it does not know where that key is kept, and it does not know who is
 * allowed to act for whom. Those are this product's answers: the key is a column on `tax_subjects`,
 * and permission is a non-revoked `subject_memberships` row. Bound here, so the platform stays a
 * mechanism and this module stays the only place a taxpayer is mentioned.
 */
final class SubjectsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SubjectDekRepository::class, EloquentSubjectDekRepository::class);
        $this->app->bind(TenantGate::class, MembershipTenantGate::class);
    }
}
