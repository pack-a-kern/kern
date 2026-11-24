<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds `Kern\Platform\Tenancy\TenantContext` from the `{subject}` route parameter before a
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
     * Matched by route *name*, not a URI substring: a future route whose path merely contained one
     * of these words must not inherit the exemption.
     *
     * @var list<string>
     */
    private const array ALWAYS_ALLOWED = ['documents.ai-consent.store', 'documents.ai-consent.destroy'];


    public function __construct(private readonly TenantContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $subjectId = $request->route('subject');
        $user = $request->user();

        abort_unless(is_string($subjectId) && $user !== null, 400, 'A tax subject route parameter and an authenticated user are both required.');

        // A `{subject}` that is not a UUID can only be a 404 — no taxpayer has that id. Answered here,
        // before the gate, so Postgres is never handed a malformed id to raise a `22P02` on, which
        // surfaces as a 500 and tells a stranger their guess at least reached the database.
        abort_unless(Str::isUuid($subjectId), 404);

        // A Postgres `uuid` column compares case-insensitively, so the gate authorises the same
        // taxpayer however its id is cased — but the raw string is what the per-taxpayer rate limiter
        // keys on and what controllers hand to the encryption AAD and object keys. Left as the URL
        // wrote it, re-casing the UUID mints a fresh rate budget and seals data under a key a later,
        // canonical read cannot reproduce. Canonical UUID form is lower-case, and this middleware is
        // the one place every subject-scoped route passes through, so settle it here — for the bound
        // context, and for the `{subject}` the controller reads back.
        $subjectId = strtolower($subjectId);
        $request->route()?->setParameter('subject', $subjectId);

        $this->context->authorizeAndActivate($user->id, $subjectId);

        $this->refuseWritesToInactive($request, $subjectId);

        return $next($request);
    }

    /**
     * A closed taxpayer is readable, never writable.
     *
     * Closed means "finished", not "sealed". The records
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

        if (in_array($request->route()?->getName(), self::ALWAYS_ALLOWED, true)) {
            return;
        }

        // `value()` runs the model's casts, so this is the enum itself, never its string.
        $status = TaxSubject::query()->whereKey($subjectId)->value('status');

        if ($status !== SubjectStatus::CLOSED) {
            return;
        }

        abort(response()->json([
            'message' => 'This taxpayer is closed. Reactivate it to make changes.',
            'reason' => 'SUBJECT_CLOSED',
        ], 409));
    }
}
