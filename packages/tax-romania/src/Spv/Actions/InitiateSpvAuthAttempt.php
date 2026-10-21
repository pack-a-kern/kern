<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Actions;

use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Spv\Contracts\SpvBrowserSessionClient;
use Kern\TaxRomania\Spv\Enums\SpvAuthMethod;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;
use Kern\TaxRomania\Spv\Exceptions\SpvAlreadySignedInException;
use Kern\TaxRomania\Spv\Exceptions\SpvAuthMethodMismatchException;
use Kern\TaxRomania\Spv\Models\SpvConnection;
use Kern\TaxRomania\Spv\Models\SpvSession;

/**
 * `BLUEPRINT.md` §13.2 steps 1-4: the user initiates "Connect SPV", Laravel starts a browser worker
 * session, and creates the local `spv_sessions` row that tracks it.
 *
 * ## One sign-in per connection, enforced here and not only by the screen
 *
 * The screen hides "Sign in" while a connection is signed in, and closes its browser when the dialog is
 * cancelled. Neither binds a request that did not come from the screen, and each call here opens a
 * Chromium on the worker — so this is where the rule holds:
 *
 *  - a connection that is **signed in** is refused ({@see SpvAlreadySignedInException}): its browser is
 *    the one every sync reads in, and replacing it would cost another emailed code for nothing;
 *  - a sign-in **begun and never finished** — the tab closed before the code — is closed first, so a
 *    second attempt replaces the first instead of leaving its browser open until the worker's TTL.
 *
 * Under a lock on the connection, so two requests at once cannot both find nothing open and both
 * start one. The worker's own ceiling bounds the total across every connection; this bounds each one.
 *
 * §4.7's MFA requirement is enforced by the `verify-recent` middleware on this action's route, not
 * here. It used to be checked in this constructor, as enrolment — which an attacker holding the
 * session passes without owning any factor. The route now demands a code, recently, which is the
 * thing the requirement was always after.
 */
final readonly class InitiateSpvAuthAttempt
{
    public function __construct(
        private SpvBrowserSessionClient $client,
        private AuditChainService $audit,
        private DestroySpvSession $destroy,
    ) {
    }

    public function __invoke(User $actingUser, string $connectionId): SpvSession
    {
        return DB::transaction(function () use ($actingUser, $connectionId): SpvSession {
            /** @var SpvConnection $connection */
            $connection = SpvConnection::query()->lockForUpdate()->findOrFail($connectionId);

            return $this->start($actingUser, $connection);
        });
    }

    private function start(User $actingUser, SpvConnection $connection): SpvSession
    {
        if ($connection->auth_method !== SpvAuthMethod::USER_PASSWORD_OTP) {
            throw SpvAuthMethodMismatchException::expected(SpvAuthMethod::USER_PASSWORD_OTP, $connection->auth_method);
        }

        if (SpvSession::query()->where('connection_id', $connection->id)->live()->exists()) {
            throw SpvAlreadySignedInException::forConnection();
        }

        /** @var SpvSession $unfinished */
        foreach (SpvSession::query()->where('connection_id', $connection->id)->unfinished()->get() as $unfinished) {
            ($this->destroy)($unfinished->id, $actingUser->id);
        }

        $browserSession = $this->client->createSession();

        $session = SpvSession::create([
            'tax_subject_id' => $connection->tax_subject_id,
            'connection_id' => $connection->id,
            'browser_session_ref' => $browserSession->id,
            'state' => SpvSessionState::CREATED,
            'created_at' => now('UTC'),
            'expires_at' => $browserSession->expiresAt,
        ]);

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $session->tax_subject_id,
            actorId: $actingUser->id,
            eventType: 'SpvAuthAttemptInitiated',
            resourceType: 'SpvSession',
            resourceId: $session->id,
            payloadHash: $this->audit->hashPayload([]),
        ));

        return $session;
    }
}
