<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Spv;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Spv\Actions\RegisterSpvConnection;
use Kern\TaxRomania\Spv\DataTransferObjects\RegisterSpvConnectionInput;
use Kern\TaxRomania\Spv\Enums\SpvAuthMethod;

/**
 * `POST .../spv/connections` — record that this taxpayer uses SPV, and how they sign in.
 *
 * A connection is not a saved login. It holds the method, the username where there is one, and what
 * happened last; the password is never part of it, here or anywhere.
 *
 * The MFA gate is the action's, not this controller's: §4.7 requires it for connecting SPV, and
 * putting it behind the HTTP layer would leave a second call site free to skip it.
 */
final class RegisterSpvConnectionController
{
    public function __invoke(Request $request, string $subject, RegisterSpvConnection $register): JsonResponse
    {
        $validated = $request->validate([
            'auth_method' => ['required', Rule::enum(SpvAuthMethod::class)],
            // Required for the portal, meaningless for a certificate: the certificate carries the
            // identity, and a username beside it would be a second answer to the same question.
            'username' => ['required_if:auth_method,USER_PASSWORD_OTP', 'nullable', 'string', 'max:120'],
        ]);

        $method = SpvAuthMethod::from((string) $validated['auth_method']);
        $username = is_string($validated['username'] ?? null) ? trim($validated['username']) : null;

        /** @var User $user */
        $user = $request->user();

        $connection = $register($user, new RegisterSpvConnectionInput(
            taxSubjectId: $subject,
            authMethod: $method,
            username: $method === SpvAuthMethod::USER_PASSWORD_OTP ? $username : null,
        ));

        return response()->json(['id' => $connection->id, 'status' => $connection->status->value], 201);
    }
}
