<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Actions\RegisterUser;
use App\Domain\Identity\Exceptions\EmailAlreadyRegisteredException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/v1/auth/register` — creates the `User` account only (`BLUEPRINT.md` §5.1). Registering
 * a `TaxSubject` (the PF profile, CNP, etc.) is a separate, later step through
 * `App\Domain\Subjects\Actions\RegisterPfSubject` once the user is authenticated — this endpoint
 * exists purely so a login can be exercised end-to-end without a human seeding a database row.
 */
final class RegistrationController extends Controller
{
    public function __invoke(Request $request, RegisterUser $register): JsonResponse
    {
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
