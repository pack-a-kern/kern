<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Subjects;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\TaxRomania\Subjects\Actions\RegisterPfSubject;
use Kern\TaxRomania\Subjects\DataTransferObjects\RegisterPfSubjectInput;
use Kern\TaxRomania\Subjects\Exceptions\DuplicateCnpException;
use Kern\TaxRomania\Subjects\Exceptions\InvalidCnpException;
use Kern\TaxRomania\Subjects\Exceptions\SubjectLimitReachedException;
use Kern\TaxRomania\Subjects\Support\CnpParser;
use Kern\TaxRomania\Wallet\Actions\EnsureGeneralSource;

/**
 * `POST /api/v1/subjects/pf` — registers a new PF `TaxSubject` for the authenticated user, who
 * becomes its `OWNER`. Reuses `Kern\TaxRomania\Subjects\Actions\RegisterPfSubject` verbatim
 * (`docs/AGENTS.md` §4 "search for existing code before writing new code") — this task only adds
 * the missing HTTP entry point `docs/handoff/030-pwa-frontend.md`'s Key decision #1 found absent;
 * no registration logic lives here.
 *
 * CNP/name/address/birth date are request-validated for shape only (never a fiscal decision,
 * `BLUEPRINT.md` §2.3) and never logged: a failed `$request->validate()` response echoes back a
 * field *name*, never its value, and no line in this controller writes the request body anywhere
 * (`BLUEPRINT.md` §4.10, `docs/AGENTS.md` rule 12).
 */
final class RegisterPfSubjectController
{
    public function __invoke(
        Request $request,
        RegisterPfSubject $register,
        EnsureGeneralSource $ensureGeneralSource,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        /** @var list<string> $roles */
        $roles = array_values(array_filter($user->getRoleNames()->all(), is_string(...)));

        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            // Shape only here; `CnpParser` below decides whether it is a CNP this application
            // accepts, and says precisely why when it is not.
            'cnp' => ['required', 'string', 'max:32'],
            // From `config/reference/counties.php`, the same list the form offers — a dropdown is a
            // convenience, this is the rule.
            'county_code' => ['nullable', 'string', Rule::in(array_keys((array) config('reference.counties.ro')))],
            'locality' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'anaf_tax_office_code' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            // Derived, never taken from the request: the birth date and the county live inside the
            // CNP, and a payload that disagrees with its own identifier must not be able to decide
            // which one is stored.
            $details = CnpParser::parse($data['cnp']);
        } catch (InvalidCnpException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason,
            ], 422);
        }

        try {
            $subject = $register(new RegisterPfSubjectInput(
                registeringUserId: $user->id,
                registeringUserRoles: $roles,
                displayName: $data['display_name'],
                firstName: $data['first_name'],
                lastName: $data['last_name'],
                cnp: $data['cnp'],
                birthDate: $details->birthDate,
                countyCode: $data['county_code'] ?? $details->countyCode,
                locality: $data['locality'] ?? null,
                address: $data['address'] ?? null,
                anafTaxOfficeCode: $data['anaf_tax_office_code'] ?? null,
            ));
        } catch (SubjectLimitReachedException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'SUBJECT_LIMIT_REACHED',
                'limit' => $exception->limit,
            ], 422);
        } catch (DuplicateCnpException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'DUPLICATE_CNP',
            ], 422);
        }

        // Every taxpayer gets the source everything lands in, so recording income needs no setup
        // first. Done here rather than inside `RegisterPfSubject` because `Domain/Subjects` may not
        // reach into `Domain/Wallet` — the HTTP layer is where the two legitimately meet.
        $ensureGeneralSource($subject->id, $request->user()?->id);

        return response()->json(['id' => $subject->id], 201);
    }
}
