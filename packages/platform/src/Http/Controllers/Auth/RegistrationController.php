<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Kern\Platform\Identity\Jobs\RegisterAccount;
use Kern\Platform\Identity\Services\RegistrationPolicy;

/**
 * `POST /api/v1/auth/register` — creates the `User` account only (`BLUEPRINT.md` §5.1). Registering
 * a `TaxSubject` (the PF profile, CNP, etc.) is a separate, later step through
 * `Kern\TaxRomania\Subjects\Actions\RegisterPfSubject` once the user is authenticated — this endpoint
 * exists purely so a login can be exercised end-to-end without a human seeding a database row.
 *
 * Closed instances refuse here rather than only hiding the link: the endpoint is reachable with a
 * terminal whatever the interface shows, so a switch enforced in the page is not a switch.
 *
 * ## The same answer for every address
 *
 * It used to say "already registered" for an address with an account, so anyone could ask whether a
 * given person keeps their taxes here — and collect the ones who do, to try passwords on. Now every
 * valid request gets the same 202 with an empty body, in the same time: the password is hashed here,
 * whichever address it is, and everything that differs — an account made, or its owner told someone
 * tried — happens afterwards in `Jobs\RegisterAccount`, where nobody is waiting on it. An
 * administrator opening an account still hears that an address is taken; that screen is theirs alone.
 */
final class RegistrationController
{
    public function __invoke(Request $request, RegistrationPolicy $policy): JsonResponse
    {
        // Before validation: a closed instance owes a stranger the same answer whatever they typed,
        // and validating first would let one probe field rules through a door that is shut.
        abort_unless($policy->isOpen(), 403, __('platform::auth.registration_closed'));

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:12', 'max:255'],
        ]);

        $password = $data['password'] ?? null;

        RegisterAccount::dispatch($data['email'], $password !== null ? Hash::make($password) : null);

        return response()->json([], 202);
    }
}
