<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Services;

use Illuminate\Support\Carbon;
use Kern\TaxRomania\Spv\Contracts\SpvBrowserSessionClient;
use Kern\TaxRomania\Spv\DataTransferObjects\PortalAuthResult;
use Kern\TaxRomania\Spv\Enums\SpvConnectionStatus;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;
use Kern\TaxRomania\Spv\Events\SpvAuthChallengeRequired;
use Kern\TaxRomania\Spv\Events\SpvAuthenticated;
use Kern\TaxRomania\Spv\Exceptions\SpvWorkerUnavailableException;
use Kern\TaxRomania\Spv\Models\SpvConnection;
use Kern\TaxRomania\Spv\Models\SpvSession;

/**
 * The one place a worker's `PortalAuthResult` is turned into `spv_sessions.state` +
 * `spv_connections.status` writes and the matching broadcast — shared by
 * `Kern\TaxRomania\Spv\Actions\{SubmitSpvCredentials,SubmitSpvChallengeCode}` so the two call sites
 * can never drift on what each outcome means.
 */
final readonly class ApplyPortalAuthResult
{
    private const int MAX_PORTAL_MESSAGE_LENGTH = 500;

    public function __construct(private SpvBrowserSessionClient $client)
    {
    }


    public function __invoke(SpvSession $session, SpvConnection $connection, PortalAuthResult $result): void
    {
        $session->state = $result->state;

        /*
         * Mirrored from the worker, which pushes it forward on every run because the TTL measures
         * idleness rather than age. Not recomputed here: this side does not know the worker's TTL, and
         * guessing it would mean a screen hiding an action for a browser that is still open.
         */
        if ($result->sessionExpiresAt !== null) {
            // Through Carbon because that is the cast's type; the instant is unchanged.
            $session->expires_at = Carbon::instance($result->sessionExpiresAt);
        }

        if ($result->state === SpvSessionState::AWAITING_USER_CHALLENGE) {
            $session->challenge_requested_at = now('UTC');
        }

        $session->save();

        match ($result->state) {
            SpvSessionState::AWAITING_USER_CHALLENGE => SpvAuthChallengeRequired::dispatch($session->id, $session->tax_subject_id),
            SpvSessionState::AUTHENTICATED => $this->markAuthenticated($session, $connection),
            SpvSessionState::FAILED => $this->markFailed($session, $connection, $result->reason),
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

    /**
     * Records the refusal twice over: as this application's own code, and as whatever the portal said.
     *
     * The code is closed and translated; the message is ANAF's Romanian, shown as-is because
     * paraphrasing "Utilizatorul sau parola nu sunt corecte" into "sign-in failed" throws away the one
     * thing the person needed — which of the two was wrong.
     *
     * Capped at a length nobody has to think about. A `message` is whatever text the playbook's
     * selector matched, and a selector aimed one element too high would hand over a page. React escapes
     * it on the way out, so the risk here is a wall of text rather than markup.
     */
    private function markFailed(SpvSession $session, SpvConnection $connection, ?string $reason): void
    {
        $connection->status = SpvConnectionStatus::FAILED;
        $connection->last_error_code = 'authentication_failed';
        $connection->last_error_message = $reason === null ? null : mb_substr(trim($reason), 0, self::MAX_PORTAL_MESSAGE_LENGTH);
        $connection->save();

        $this->releaseBrowser($session);
    }

    /**
     * Closes the browser behind a sign-in that has failed.
     *
     * Nothing was doing this. `SubmitSpvChallengeCode::expire()` released the browser when the local
     * challenge window ran out, but a portal that *refused* — or a step that timed out — left an open
     * Chromium holding whatever state the attempt had reached, for the rest of the worker's own session
     * TTL, with nothing on this side that would ever use it again. Observed as a live session in
     * `/v1/stats` minutes after a failure the person had already been told about.
     *
     * It matters most in the case that looks least like a leak: ANAF accepts the code, the step after it
     * fails for an unrelated reason, and the browser is left holding an *authenticated* portal session.
     * A session nobody can use should not be a session that still exists.
     *
     * Best-effort, the same way `expire()` is: the local records are already written, and a worker that
     * cannot be reached to release its own resources will expire them at its TTL. Failing the request
     * over a teardown would tell the person their sign-in broke twice.
     */
    private function releaseBrowser(SpvSession $session): void
    {
        $session->destroyed_at = now('UTC');
        $session->save();

        try {
            $this->client->destroySession($session->browser_session_ref);
        } catch (SpvWorkerUnavailableException) {
            // The worker's own TTL sweeper closes what this could not.
        }
    }
}
