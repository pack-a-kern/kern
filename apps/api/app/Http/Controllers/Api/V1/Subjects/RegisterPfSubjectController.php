<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Subjects;

use App\Domain\Subjects\Actions\RegisterPfSubject;
use App\Domain\Subjects\DataTransferObjects\RegisterPfSubjectInput;
use App\Domain\Subjects\Exceptions\DuplicateCnpException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/subjects/pf` — registers a new PF `TaxSubject` for the authenticated user, who
 * becomes its `OWNER`. Reuses `App\Domain\Subjects\Actions\RegisterPfSubject` verbatim
 * (`docs/AGENTS.md` §4 "search for existing code before writing new code") — this task only adds
 * the missing HTTP entry point `docs/handoff/030-pwa-frontend.md`'s Key decision #1 found absent;
 * no registration logic lives here.
 *
 * CNP/name/address/birth date are request-validated for shape only (never a fiscal decision,
 * `BLUEPRINT.md` §2.3) and never logged: a failed `$request->validate()` response echoes back a
 * field *name*, never its value, and no line in this controller writes the request body anywhere
 * (`BLUEPRINT.md` §4.10, `docs/AGENTS.md` rule 12).
 */
final class RegisterPfSubjectController extends Controller
{
    public function __invoke(Request $request, RegisterPfSubject $register): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            // 13 digits, tolerant of the spaces/dashes some data-entry UIs insert — the same
            // shape App\Support\Crypto\BlindIndex\Normalizer::cnp() already tolerates.
            'cnp' => ['required', 'string', 'regex:/^[0-9\s-]{13,19}$/'],
            'birth_date' => ['required', 'date_format:Y-m-d'],
            'county_code' => ['nullable', 'string', 'max:10'],
            'locality' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'anaf_tax_office_code' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $subject = $register(new RegisterPfSubjectInput(
                registeringUserId: $user->id,
                displayName: $data['display_name'],
                firstName: $data['first_name'],
                lastName: $data['last_name'],
                cnp: $data['cnp'],
                birthDate: $data['birth_date'],
                countyCode: $data['county_code'] ?? null,
                locality: $data['locality'] ?? null,
                address: $data['address'] ?? null,
                anafTaxOfficeCode: $data['anaf_tax_office_code'] ?? null,
            ));
        } catch (DuplicateCnpException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'DUPLICATE_CNP',
            ], 422);
        }

        return response()->json(['id' => $subject->id], 201);
    }
}
