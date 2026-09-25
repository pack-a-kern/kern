<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Subjects\Enums\SubjectStatus;
use App\Domain\Subjects\Models\TaxSubject;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds `App\Support\Tenancy\TenantContext` from the `{subject}` route parameter before a
 * controller runs — the request-lifecycle piece `docs/handoff/011-identity-tenancy.md` "How
 * another module scopes a query to a subject correctly" step 2 says every module owns for itself.
 * Throws (and records a `security_events` row, via `TenantContext` itself) if the authenticated
 * user has no active membership for that subject — never reaches the controller otherwise.
 */
final class BindTaxSubjectContext
{
    /**
     * Requests that stay open on a taxpayer nobody may write to.
     *
     * Withdrawing consent to external AI processing is the exception that matters: it takes a
     * protection *away* from a third party, and a rule that made it unreachable would be a rule
     * that keeps documents flowing out of a taxpayer someone has just put beyond use.
     *
     * @var list<string>
     */
    private const array ALWAYS_ALLOWED = ['document-ai-consent'];


    public function __construct(private readonly TenantContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $subjectId = $request->route('subject');
        $user = $request->user();

        abort_unless(is_string($subjectId) && $user !== null, 400, 'A tax subject route parameter and an authenticated user are both required.');

        $this->context->authorizeAndActivate($user->id, $subjectId);

        $this->refuseWritesToInactive($request, $subjectId);

        return $next($request);
    }

    /**
     * A suspended or closed taxpayer is readable, never writable.
     *
     * `SUSPENDED` means "paused" and `CLOSED` means "finished"; neither means "sealed". The records
     * a taxpayer accumulated are exactly what someone needs years later — a fiscal file you cannot
     * open is a strange thing to leave a person with — so reads go through and only changes are
     * refused. Reactivation is not blocked by this: the status route sits outside this middleware.
     *
     * Enforced here rather than in each controller because this is the one place every
     * subject-scoped route passes through, and a rule with twenty enforcement points has twenty
     * chances to be forgotten.
     */
    private function refuseWritesToInactive(Request $request, string $subjectId): void
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        foreach (self::ALWAYS_ALLOWED as $segment) {
            if (str_contains((string) $request->route()?->uri(), $segment)) {
                return;
            }
        }

        // `value()` runs the model's casts, so this is the enum itself — comparing it to a string
        // silently matched nothing, and every refusal came back saying "suspended".
        $status = TaxSubject::query()->whereKey($subjectId)->value('status');

        if (! $status instanceof SubjectStatus || $status === SubjectStatus::ACTIVE) {
            return;
        }

        $closed = $status === SubjectStatus::CLOSED;

        abort(response()->json([
            'message' => $closed
                ? 'This taxpayer is closed. Reactivate it to make changes.'
                : 'This taxpayer is suspended. Reactivate it to make changes.',
            'reason' => $closed ? 'SUBJECT_CLOSED' : 'SUBJECT_SUSPENDED',
        ], 409));
    }
}
