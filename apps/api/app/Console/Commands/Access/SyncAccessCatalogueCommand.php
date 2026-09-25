<?php

declare(strict_types=1);

namespace App\Console\Commands\Access;

use App\Domain\Access\Services\AccessCatalogue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Projects `config/access.php` onto the roles and permissions tables.
 *
 * Additive by design. Missing roles and permissions are created and role→permission assignments
 * are made to match the file; anything present in the database but absent from the file is
 * *reported*, never deleted. Revoking a permission changes who can do what, which is a decision
 * someone should make explicitly rather than inherit from an edit that happened to remove a line.
 *
 * Grants nothing to anybody: `kern:access:grant` is how a user receives a role.
 */
final class SyncAccessCatalogueCommand extends Command
{
    protected $signature = 'kern:access:sync {--dry-run : Report what would change without writing}';

    protected $description = 'Create the roles and permissions declared in config/access.php (BLUEPRINT.md §5.3).';

    public function handle(AccessCatalogue $catalogue): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $created = DB::transaction(function () use ($catalogue, $dryRun): array {
            $log = [];

            foreach ($catalogue->permissions() as $name) {
                if (Permission::query()->where('name', $name)->exists()) {
                    continue;
                }

                $log[] = "permission {$name}";

                if (! $dryRun) {
                    Permission::create(['name' => $name, 'guard_name' => 'web']);
                }
            }

            foreach ($catalogue->roles() as $roleName) {
                $role = Role::query()->where('name', $roleName)->first();

                if ($role === null) {
                    $log[] = "role {$roleName}";

                    if ($dryRun) {
                        continue;
                    }

                    $role = Role::create(['name' => $roleName, 'guard_name' => 'web']);
                }

                if (! $dryRun) {
                    // syncPermissions, not givePermissionTo: the file is the source of truth for
                    // what a role holds, so a permission removed from a role in config is removed
                    // here too. (Deleting the *permission itself* is what this command never does.)
                    $role->syncPermissions($catalogue->permissionsFor($roleName));
                }
            }

            return $log;
        });

        foreach ($created as $line) {
            $this->line(($dryRun ? 'would create ' : 'created ') . $line);
        }

        $undeclared = Permission::query()
            ->whereNotIn('name', $catalogue->permissions())
            ->pluck('name')
            ->all();

        foreach ($undeclared as $name) {
            if (is_scalar($name)) {
                $this->warn('undeclared permission in database, left untouched: ' . (string) $name);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info(sprintf(
            '%s %d permissions and %d roles.',
            $dryRun ? 'Would sync' : 'Synced',
            count($catalogue->permissions()),
            count($catalogue->roles()),
        ));

        return self::SUCCESS;
    }
}
