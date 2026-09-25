<?php

declare(strict_types=1);

namespace Kern\Platform\Tenancy;

use Kern\Platform\Tenancy\Contracts\TenantGate;
use Kern\Platform\Tenancy\Exceptions\NoTenantContextException;
use Kern\Platform\Tenancy\Exceptions\UnauthorizedTenantAccessException;

/**
 * The one sanctioned way to say which tenant this request or job is acting for, and as whom.
 *
 * There is deliberately no lower-level "just set the id, trust me" method. Binding goes through
 * `authorizeAndActivate()`, which asks the application's {@see TenantGate} every time — so an
 * unauthorized cross-tenant read has no shortcut to reach for, only the named escape that
 * {@see ScopedToTenant} documents.
 *
 * **Bind it as a scoped instance, never a process-lifetime singleton.** Its state is which tenant
 * is active, and state like that leaking from one request or queue job into the next is the whole
 * catastrophe this mechanism exists to prevent — the same requirement
 * `Kern\Platform\KeyManagement\SubjectDekService` states for itself.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    private ?string $role = null;

    public function __construct(private readonly TenantGate $gate)
    {
    }

    /**
     * Bind this context to a tenant the user may act for, or refuse.
     *
     * The check is made here, every time, against whatever the application counts as permission —
     * never cached from an earlier request, and never inferred from the fact that an id was
     * supplied.
     */
    public function authorizeAndActivate(string $userId, string $tenantId): void
    {
        $role = $this->gate->roleFor($userId, $tenantId);

        if ($role === null) {
            throw UnauthorizedTenantAccessException::forUser($userId, $tenantId);
        }

        $this->tenantId = $tenantId;
        $this->role = $role;
    }

    public function hasActiveTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function currentTenantId(): string
    {
        return $this->tenantId ?? throw NoTenantContextException::notBound();
    }

    /** The role held for the active tenant, in the application's own vocabulary. */
    public function currentRole(): string
    {
        return $this->role ?? throw NoTenantContextException::notBound();
    }
}
