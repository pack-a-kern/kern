<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Spv;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Spv\Actions\SubmitSpvChallengeCode;
use Kern\TaxRomania\Spv\DataTransferObjects\SubmitSpvChallengeCodeInput;
use Kern\TaxRomania\Spv\ValueObjects\SensitiveString;

/**
 * `POST .../spv/sessions/{session}/challenge` — the code ANAF emailed, passed through.
 *
 * As sensitive as the password for as long as it lives, and handled identically: wrapped as it
 * leaves the request, never logged, never stored, never returned.
 */
final class SubmitSpvChallengeCodeController
{
    public function __invoke(
        Request $request,
        string $subject,
        string $session,
        SubmitSpvChallengeCode $submit,
    ): JsonResponse {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $result = $submit(new SubmitSpvChallengeCodeInput(
            sessionId: $session,
            code: SensitiveString::wrap((string) $validated['code']),
            actorId: $user->id,
        ));

        return response()->json([
            'state' => $result->state->value,
            'expires_at' => $result->expires_at->toAtomString(),
        ]);
    }
}
