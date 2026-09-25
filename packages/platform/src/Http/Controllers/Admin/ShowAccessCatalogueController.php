<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Kern\Platform\Access\Services\AccessCatalogue;
use Spatie\Permission\Models\Role;

/**
 * `GET /api/v1/admin/access` — the permission matrix, as it is actually configured.
 *
 * Every cell is read from the database (`role_has_permissions`), not from the config file, so the
 * screen shows what is in force rather than what was intended. `enforced: false` marks a
 * permission that no route or policy checks yet — it renders differently, so the matrix never
 * implies a protection that does not exist.
 */
final class ShowAccessCatalogueController
{
    public function __invoke(AccessCatalogue $catalogue): JsonResponse
    {
        $roles = Role::query()->with('permissions')->orderBy('name')->get();

        $granted = [];

        foreach ($roles as $role) {
            $granted[(string) $role->name] = $role->permissions
                ->map(static fn (object $permission): string => (string) $permission->name)
                ->all();
        }

        $groups = [];

        foreach ($catalogue->groups() as $group => $permissions) {
            $rows = [];

            foreach ($permissions as $permission) {
                $holders = [];

                foreach ($granted as $roleName => $names) {
                    $holders[$roleName] = in_array($permission, $names, true);
                }

                $rows[] = [
                    'permission' => $permission,
                    'enforced' => $catalogue->isEnforced($permission),
                    'roles' => $holders,
                ];
            }

            $groups[] = ['group' => $group, 'permissions' => $rows];
        }

        return response()->json([
            'roles' => array_keys($granted),
            'groups' => $groups,
        ]);
    }
}
