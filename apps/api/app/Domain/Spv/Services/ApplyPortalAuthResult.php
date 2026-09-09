<?php

declare(strict_types=1);

namespace App\Domain\Spv\Services;

use App\Domain\Spv\DataTransferObjects\PortalAuthResult;
use App\Domain\Spv\Enums\SpvConnectionStatus;
use App\Domain\Spv\Enums\SpvSessionState;
use App\Domain\Spv\Events\SpvAuthChallengeRequired;
use App\Domain\Spv\Events\SpvAuthenticated;
use App\Domain\Spv\Models\SpvConnection;
use App\Domain\Spv\Models\SpvSession;

/**
 * The one place a worker's `PortalAuthResult` is turned into `spv_sessions.state` +
 * `spv_connections.status` writes and the matching broadcast — shared by
 * `App\Domain\Spv\Actions\{SubmitSpvCredentials,SubmitSpvChallengeCode}` so the two call sites
 * can never drift on what each outcome means.
 */
final readonly class ApplyPortalAuthResult
{
    public function __invoke(SpvSession $session, SpvConnection $connection, PortalAuthResult $result): void
    {
        $session->state = $result->state;

        if ($result->state === SpvSessionState::AWAITING_USER_CHALLENGE) {
            $session->challenge_requested_at = now('UTC');
        }

        $session->save();

        match ($result->state) {
            SpvSessionState::AWAITING_USER_CHALLENGE => SpvAuthChallengeRequired::dispatch($session->id, $session->tax_subject_id),
            SpvSessionState::AUTHENTICATED => $this->markAuthenticated($session, $connection),
            SpvSessionState::FAILED => $this->markFailed($connection, $result->reason),
            default => null,
        };
    }

    private function markAuthenticated(SpvSession $session, SpvConnection $connection): void
    {
        $connection->status = SpvConnectionStatus::ACTIVE;
        $connection->last_success_at = now('UTC');
        $connection->last_error_code = null;
        $connection->save();

        SpvAuthenticated::dispatch($session->id, $session->tax_subject_id);
    }

    private function markFailed(SpvConnection $connection, ?string $reason): void
    {
        $connection->status = SpvConnectionStatus::FAILED;
        $connection->last_error_code = $reason ?? 'authentication_failed';
        $connection->save();
    }
}
