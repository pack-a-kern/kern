<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Actions\CreateUserAccount;
use App\Domain\Identity\Exceptions\EmailAlreadyRegisteredException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/admin/users` — opens an account for somebody else.
 *
 * The generated password comes back in this one response and is never retrievable again. It is not
 * logged: the sanitiser redacts password-shaped values, and nothing here writes it anywhere.
 *
 * Granting `SUPER_ADMIN` is reserved to a super administrator. Otherwise `users.manage` — which
 * every administrator holds — would be a way to mint the one role they were not given.
 */
final class CreateUserController extends Controller
{
    public function __invoke(Request $request, CreateUserAccount $create): JsonResponse
    {
        $administrator = $request->user();
        abort_unless($administrator !== null, 401);

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::in(['SUPER_ADMIN', 'ADMIN', 'ACCOUNTANT', 'VIEWER'])],
        ]);

        /** @var list<string> $roles */
        $roles = array_values(array_filter($data['roles'] ?? [], is_string(...)));

        abort_if(
            in_array('SUPER_ADMIN', $roles, true) && ! $administrator->hasRole('SUPER_ADMIN'),
            403,
            'Only a super administrator may grant that role.',
        );

        try {
            $created = $create($data['email'], $roles, $administrator->id);
        } catch (EmailAlreadyRegisteredException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => 'EMAIL_TAKEN',
            ], 422);
        }

        return response()->json([
            'id' => $created['user']->id,
            // Shown once, by the screen that asked for it. There is no second chance to read it.
            'password' => $created['password'],
        ], 201);
    }
}
