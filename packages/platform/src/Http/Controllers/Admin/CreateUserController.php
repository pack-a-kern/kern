<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\Platform\Access\Services\AccessCatalogue;
use Kern\Platform\Access\SuperAdministratorRole;
use Kern\Platform\Identity\Actions\CreateUserAccount;
use Kern\Platform\Identity\Exceptions\EmailAlreadyRegisteredException;

/**
 * `POST /api/v1/admin/users` — opens an account for somebody else.
 *
 * The generated password comes back in this one response and is never retrievable again. It is not
 * logged: the sanitiser redacts password-shaped values, and nothing here writes it anywhere.
 *
 * Granting `SUPER_ADMIN` is reserved to a super administrator, by the same rule the roles screen
 * applies (`SuperAdministratorRole`). Otherwise `users.manage`, given to any other role, would be a
 * way to mint the one role it was not given.
 */
final class CreateUserController
{
    public function __invoke(Request $request, CreateUserAccount $create, AccessCatalogue $catalogue): JsonResponse
    {
        $administrator = $request->user();
        abort_unless($administrator !== null, 401);

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'roles' => ['array'],
            'roles.*' => ['string', Rule::in($catalogue->roles())],
        ]);

        /** @var list<string> $roles */
        $roles = array_values(array_filter($data['roles'] ?? [], is_string(...)));

        if (! SuperAdministratorRole::allowsChange($administrator, [], $roles)) {
            return SuperAdministratorRole::refusal();
        }

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
