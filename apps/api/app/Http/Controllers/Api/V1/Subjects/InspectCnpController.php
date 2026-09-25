<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Subjects;

use App\Domain\Subjects\Exceptions\InvalidCnpException;
use App\Domain\Subjects\Queries\FindSubjectByCnp;
use App\Domain\Subjects\Support\CnpParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/subjects/cnp` — what a CNP says about the person, before anything is registered.
 *
 * The registration form asks for the CNP first and fills in the rest from this: the birth date and
 * the county are *inside* the number, and asking someone to retype them invites two fields that
 * contradict the identifier that produced them.
 *
 * ## Why the server does this rather than the browser
 *
 * The same arithmetic could run in TypeScript, and it would answer without a round trip. It would
 * also be a second copy of a rule that decides what this application accepts — including the
 * residency refusal, which is a product decision rather than a calculation. One copy, on the side
 * that also stores the result.
 *
 * Nothing is written here and nothing is looked up: the CNP is read, not stored, and it is never
 * logged (`Kern\Platform\Logging`'s redaction covers the shape in any case). Registration re-derives
 * from the CNP it is given, so a caller cannot use this endpoint to smuggle a mismatched pair in.
 */
final class InspectCnpController
{
    public function __invoke(Request $request, FindSubjectByCnp $find): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $data = $request->validate([
            'cnp' => ['required', 'string', 'max:32'],
        ]);

        try {
            $details = CnpParser::parse($data['cnp']);

            // Said here rather than at the end of the form: registration refuses a duplicate
            // anyway, and letting someone fill in six more fields before telling them is a waste
            // of their time. The answer distinguishes their own taxpayer — which is their data —
            // from one registered elsewhere, which stays exactly that vague.
            $existing = $find($data['cnp'], $user->id);

            if ($existing['registered']) {
                throw $existing['mine']
                    ? InvalidCnpException::alreadyYours()
                    : InvalidCnpException::alreadyRegistered();
            }
        } catch (InvalidCnpException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason,
            ], 422);
        }

        return response()->json($details->toArray());
    }
}
