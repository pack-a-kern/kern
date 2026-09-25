<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kern\Platform\Identity\Actions\RegisterUser;
use Kern\Platform\Identity\Exceptions\EmailAlreadyRegisteredException;
use Kern\Platform\Identity\Services\RegistrationPolicy;

/**
 * `POST /api/v1/auth/register` — creates the `User` account only (`BLUEPRINT.md` §5.1). Registering
 * a `TaxSubject` (the PF profile, CNP, etc.) is a separate, later step through
 * `Kern\TaxRomania\Subjects\Actions\RegisterPfSubject` once the user is authenticated — this endpoint
 * exists purely so a login can be exercised end-to-end without a human seeding a database row.
 *
 * Closed instances refuse here rather than only hiding the link: the endpoint is reachable with a
 * terminal whatever the interface shows, so a switch enforced in the page is not a switch.
 */
final class RegistrationController
{
    public function __invoke(Request $request, RegisterUser $register, RegistrationPolicy $policy): JsonResponse
    {
        // Before validation: a closed instance owes a stranger the same answer whatever they typed,
        // and validating first would let one probe field rules through a door that is shut.
        abort_unless($policy->isOpen(), 403, 'Registration is closed on this instance.');

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:12', 'max:255'],
        ]);

        try {
            $user = $register($data['email'], $data['password'] ?? null);
        } catch (EmailAlreadyRegisteredException $exception) {
            throw ValidationException::withMessages(['email' => [$exception->getMessage()]]);
        }

        return response()->json([
            'id' => $user->id,
        ], 201);
    }
}
