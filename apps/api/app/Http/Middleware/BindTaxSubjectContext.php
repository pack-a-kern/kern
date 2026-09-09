<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Subjects\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds `App\Domain\Subjects\Tenancy\TenantContext` from the `{subject}` route parameter before a
 * controller runs — the request-lifecycle piece `docs/handoff/011-identity-tenancy.md` "How
 * another module scopes a query to a subject correctly" step 2 says every module owns for itself.
 * Throws (and records a `security_events` row, via `TenantContext` itself) if the authenticated
 * user has no active membership for that subject — never reaches the controller otherwise.
 */
final class BindTaxSubjectContext
{
    public function __construct(private readonly TenantContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $subjectId = $request->route('subject');
        $user = $request->user();

        abort_unless(is_string($subjectId) && $user !== null, 400, 'A tax subject route parameter and an authenticated user are both required.');

        $this->context->authorizeAndActivate($user->id, $subjectId);

        return $next($request);
    }
}
