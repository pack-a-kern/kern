<?php

declare(strict_types=1);

namespace Kern\Platform\Console\Commands\Identity;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Models\User;

/**
 * Marks an address verified from the shell, without sending anything.
 *
 * ## Why this has to exist
 *
 * `identity.email_verification_required` holds every unverified account at the verification screen,
 * and the only way off that screen is a code that arrives by mail. So a deployment with that setting
 * on and mail misconfigured is a deployment nobody can enter — including whoever would fix the mail
 * settings, because the settings screen is behind the gate. A switch that can lock an installation out
 * of itself needs a door that is not behind it.
 *
 * This is that door, and it is deliberately the shell: the same trust boundary as reaching the
 * database directly, which is exactly the argument `kern:access:grant` makes for being the only way
 * the first administrator comes into existence.
 *
 * ## Why there is no `--all`
 *
 * Because there is no backfill, by decision. "This account has existed for a while" is not evidence
 * that anybody could ever read its address, and a flag that verified every account at once would make
 * the setting mean nothing on the installation that most needed it. One address at a time, named
 * explicitly, recorded in the chain.
 */
final class VerifyUserEmailCommand extends Command
{
    protected $signature = 'kern:identity:verify-email {email : The address to mark verified}
                            {--undo : Mark it unverified again instead}';

    protected $description = 'Mark an account\'s email address verified without sending a code — the way back in when mail is broken.';

    public function handle(AuditChainService $audit): int
    {
        $email = (string) $this->argument('email');
        $undo = (bool) $this->option('undo');

        // A blind-index lookup: no row is decrypted to find one.
        $user = User::whereEmail($email)->first();

        if ($user === null) {
            $this->error('No user with that email address.');

            return self::FAILURE;
        }

        $user->email_verified_at = $undo ? null : Carbon::now('UTC');
        $user->save();

        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: null,
            eventType: $undo ? 'EmailVerificationRevoked' : 'EmailVerified',
            resourceType: 'User',
            resourceId: $user->id,
            // `console` rather than an actor id: nobody signed in to do this, and recording a user id
            // as the actor would claim somebody did.
            payloadHash: $audit->hashPayload(['channel' => 'CONSOLE', 'undo' => $undo]),
        ));

        $this->info($undo
            ? sprintf('%s is no longer verified.', $email)
            : sprintf('%s is now verified.', $email));

        return self::SUCCESS;
    }
}
