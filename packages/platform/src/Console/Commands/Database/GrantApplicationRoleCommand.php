<?php

declare(strict_types=1);

namespace Kern\Platform\Console\Commands\Database;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Kern\Audit\Models\AuditEvent;

/**
 * Creates or updates the database role the running application connects as, and grants it exactly
 * what it uses.
 *
 *     printf '%s\n' "$password" | php artisan kern:db:grant-app-role --stdin kern_app
 *
 * Run as the *owner* — the role that creates the tables, which is what `migrate` connects as — never
 * as the application itself, and after every migration, which is how `docker compose run --rm
 * migrate` runs it.
 *
 * ## Why the application stopped connecting as the owner
 *
 * It used to connect as `kern`, the role the Postgres image creates: a superuser, and the owner of
 * every table. One SQL injection anywhere would then have been the whole database server —
 * `COPY … TO PROGRAM` runs a shell command in the database container — and the owner of
 * `audit_events` can `ALTER TABLE … DISABLE TRIGGER` and rewrite the history the trigger exists to
 * protect. The role this grants is none of that:
 *
 * - **No attributes.** Not a superuser, cannot create roles or databases, cannot replicate, does not
 *   bypass row security. It owns nothing, so it cannot alter, drop or disable anything.
 * - **Rows, not schema.** Reads and writes rows in every table, uses the sequences, and nothing more.
 *   Default privileges give it the same on every table a later migration creates, so a new feature
 *   works without running this again — though running it again is harmless.
 * - **The audit trail is append-only to it.** On `audit_events` it may add and read, and no more: no
 *   `UPDATE`, `DELETE` or `TRUNCATE`. The trigger refuses the first two as well; this is the half the
 *   owner cannot switch off from the application's connection, because that connection is not the
 *   owner's.
 *
 * Idempotent: an existing role has its password and attributes set again, and every grant is
 * restated. The password is read from standard input, never an argument, so it reaches neither `ps`
 * nor the shell's history.
 */
final class GrantApplicationRoleCommand extends Command
{
    /** @var string */
    protected $signature = 'kern:db:grant-app-role
                            {role : The role the application connects as (DB_USERNAME)}
                            {--stdin : Read its password from standard input}';

    /** @var string */
    protected $description = 'Create or update the limited role the application connects to the database as.';

    public function handle(DatabaseManager $databases): int
    {
        $db = $databases->connection();

        if ($db->getDriverName() !== 'pgsql') {
            $this->error('Roles are a Postgres feature; this connection is ' . $db->getDriverName() . '.');

            return self::FAILURE;
        }

        $role = (string) $this->argument('role');

        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            $this->error('A role name is lower-case letters, digits and underscores, starting with a letter.');

            return self::FAILURE;
        }

        /** @var object{name: string} $owner */
        $owner = $db->selectOne('select current_user as name');

        // Granting to the role this runs as would be a no-op at best, and resetting its attributes
        // below would take the owner's own away — the application and the owner are different roles
        // by definition.
        if ($role === $owner->name) {
            $this->error("{$role} is the role this is running as — the owner. The application's role must be a different one.");

            return self::FAILURE;
        }

        $password = $this->readPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $quotedPassword = $db->getPdo()->quote($password);
        $database = $db->getDatabaseName();
        $attributes = 'LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT';

        /** @var object{exists: bool}|null $existing */
        $existing = $db->selectOne('select true as exists from pg_roles where rolname = ?', [$role]);

        try {
            $db->statement($existing === null
                ? "CREATE ROLE \"{$role}\" {$attributes} PASSWORD {$quotedPassword}"
                : "ALTER ROLE \"{$role}\" {$attributes} PASSWORD {$quotedPassword}");
        } catch (QueryException $exception) {
            // Not rethrown: its message carries the statement, and the statement carries the password.
            // The SQLSTATE says enough — 42501 is the owner lacking CREATEROLE, on a server you run.
            $this->error("Could not create or update {$role} (SQLSTATE {$exception->getCode()}). The owner needs CREATEROLE.");

            return self::FAILURE;
        }

        $auditEvents = (new AuditEvent())->getTable();

        foreach ([
            "GRANT CONNECT ON DATABASE \"{$database}\" TO \"{$role}\"",
            "GRANT USAGE ON SCHEMA public TO \"{$role}\"",
            "GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO \"{$role}\"",
            "GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO \"{$role}\"",
            "ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner->name}\" IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO \"{$role}\"",
            "ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner->name}\" IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO \"{$role}\"",
            // After the blanket grant above, which includes this table: add and read, and nothing else.
            "REVOKE UPDATE, DELETE, TRUNCATE ON \"{$auditEvents}\" FROM \"{$role}\"",
        ] as $statement) {
            $db->statement($statement);
        }

        $this->info("{$role} can read and write rows, and add to {$auditEvents} without changing it. It owns nothing.");

        return self::SUCCESS;
    }

    private function readPassword(): ?string
    {
        if (! (bool) $this->option('stdin')) {
            $this->error('Pipe the password in with --stdin: it is never taken as an argument, where `ps` would show it.');

            return null;
        }

        $line = fgets(STDIN);
        $password = $line === false ? '' : rtrim($line, "\r\n");

        if (strlen($password) < 16) {
            $this->error('No password on standard input, or one shorter than 16 characters. The installer generates 48.');

            return null;
        }

        return $password;
    }
}
