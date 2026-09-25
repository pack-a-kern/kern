<?php

declare(strict_types=1);

namespace App\Domain\Access\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

/**
 * Reads `config/access.php` — the declared set of platform roles and permissions.
 *
 * The database rows created by `kern:access:sync` are a projection of this file, never the other
 * way round: a permission that exists only in the database is reported by the sync command as
 * undeclared rather than silently honoured, because an authorization set that can be edited
 * without review is not one you can reason about.
 */
final readonly class AccessCatalogue
{
    public function __construct(private ConfigRepository $config)
    {
    }

    /**
     * Every declared permission name, flattened.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        $names = [];

        foreach ($this->groups() as $group) {
            foreach ($group as $permission) {
                $names[] = $permission;
            }
        }

        return $names;
    }

    /**
     * Permissions keyed by their display group, in declaration order.
     *
     * @return array<string, list<string>>
     */
    public function groups(): array
    {
        $groups = $this->config->get('access.permissions');

        if (! is_array($groups)) {
            throw new RuntimeException('access.permissions is missing or is not an array.');
        }

        $result = [];

        foreach ($groups as $name => $permissions) {
            if (! is_string($name) || ! is_array($permissions)) {
                throw new RuntimeException('Each access.permissions group must be a named list.');
            }

            $result[$name] = array_values(array_filter($permissions, is_string(...)));
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        return array_keys($this->roleDefinitions());
    }

    /**
     * The permissions a role holds. `*` expands to every declared permission.
     *
     * @return list<string>
     */
    public function permissionsFor(string $role): array
    {
        $declared = $this->roleDefinitions()[$role] ?? [];

        return in_array('*', $declared, true) ? $this->permissions() : $declared;
    }

    /** True when nothing in the application actually checks this permission yet. */
    public function isEnforced(string $permission): bool
    {
        $unenforced = $this->config->get('access.not_enforced');

        return ! (is_array($unenforced) && in_array($permission, $unenforced, true));
    }

    /**
     * @return array<string, list<string>>
     */
    private function roleDefinitions(): array
    {
        $roles = $this->config->get('access.roles');

        if (! is_array($roles)) {
            throw new RuntimeException('access.roles is missing or is not an array.');
        }

        $definitions = [];

        foreach ($roles as $name => $permissions) {
            if (is_string($name) && is_array($permissions)) {
                $definitions[$name] = array_values(array_filter($permissions, is_string(...)));
            }
        }

        return $definitions;
    }
}
