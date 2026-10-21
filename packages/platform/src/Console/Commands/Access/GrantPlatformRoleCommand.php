<?php

declare(strict_types=1);

namespace Kern\Platform\Console\Commands\Access;

use Illuminate\Console\Command;
use Kern\Platform\Access\Services\AccessCatalogue;
use Kern\Platform\Access\SuperAdministratorRole;
use Kern\Platform\Identity\Models\User;

/**
 * Grants or revokes a platform role from the shell.
 *
 * This is how the *first* administrator comes into existence. Roles are otherwise granted through
 * Administration → Users & roles, which itself requires `users.manage` — so on a fresh install, or
 * after the last super administrator is lost, there would be no way in. Requiring shell access to
 * bootstrap is the point: it is the same trust boundary as reaching the database directly.
 *
 * Replaces the `SETTINGS_ADMIN_USER_IDS` environment allowlist (ADR 0013), which granted access by
 * configuration rather than by a record anyone could inspect — see docs/adr/0014.
 */
final class GrantPlatformRoleCommand extends Command
{
    protected $signature = 'kern:access:grant {email : The user\'s email address}
                            {role : A role from config/access.php}
                            {--revoke : Remove the role instead of granting it}';

    protected $description = 'Grant or revoke a platform role for a user (BLUEPRINT.md §5.3).';

    public function handle(AccessCatalogue $catalogue): int
    {
        $email = (string) $this->argument('email');
        $role = (string) $this->argument('role');

        if (! in_array($role, $catalogue->roles(), true)) {
            $this->error(sprintf('Unknown role [%s]. Declared roles: %s.', $role, implode(', ', $catalogue->roles())));

            return self::FAILURE;
        }

        // `whereEmail` is a blind-index lookup — no row is decrypted to find one (§4.4).
        $user = User::whereEmail($email)->first();

        if ($user === null) {
            $this->error('No user with that email address.');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            // Guarded the same way the HTTP endpoint is: an installation with no super
            // administrator can only be repaired from here, and this *is* here.
            if ($role === SuperAdministratorRole::NAME && SuperAdministratorRole::activeHoldersOtherThan($user) === 0) {
                $this->warn(sprintf('This is the last %s. Revoking it leaves nobody able to grant roles through the UI.', SuperAdministratorRole::NAME));

                if (! $this->confirm('Revoke anyway?', false)) {
                    return self::FAILURE;
                }
            }

            $user->removeRole($role);
            $this->info("Revoked {$role} from {$email}.");

            return self::SUCCESS;
        }

        $user->assignRole($role);
        $this->info("Granted {$role} to {$email}.");

        return self::SUCCESS;
    }
}
