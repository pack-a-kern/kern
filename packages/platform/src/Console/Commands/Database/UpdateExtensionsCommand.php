<?php

declare(strict_types=1);

namespace Kern\Platform\Console\Commands\Database;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;

/**
 * Brings every installed Postgres extension up to the version its image ships.
 *
 *     php artisan kern:db:update-extensions
 *
 * A newer image — `pgvector/pgvector:0.8.7` after `0.8.6` — brings its new library at once, but the
 * extension's own objects in the database stay at the version they were created with until
 * `ALTER EXTENSION … UPDATE`, and nothing ran that for a database that already existed. So `migrate`
 * runs this after every migration, as the owner: it updates what is behind and leaves the rest.
 *
 * Not a migration on purpose. A migration runs once — run before Postgres had been restarted on the
 * new image, it would update to the old version, be marked done, and never run again. This asks the
 * database each time what its image offers (`pg_extension` against `pg_available_extensions`).
 */
final class UpdateExtensionsCommand extends Command
{
    /** @var string */
    protected $signature = 'kern:db:update-extensions';

    /** @var string */
    protected $description = 'Update every installed Postgres extension to the version its image ships.';

    /** An extension name this can write into the statement without quoting it. */
    private const string NAME = '/^[a-z_][a-z0-9_]*$/';

    public function handle(DatabaseManager $databases): int
    {
        $db = $databases->connection();

        if ($db->getDriverName() !== 'pgsql') {
            $this->info('No extensions: this connection is ' . $db->getDriverName() . '.');

            return self::SUCCESS;
        }

        $behind = $db->select(
            'select e.extname as name, e.extversion as installed, a.default_version as offered
               from pg_extension e
               join pg_available_extensions a on a.name = e.extname
              where e.extversion is distinct from a.default_version
              order by e.extname',
        );

        if ($behind === []) {
            $this->info('Every extension is at the version its image ships.');

            return self::SUCCESS;
        }

        foreach ($behind as $row) {
            /** @var object{name: string, installed: string, offered: string} $row */
            if (preg_match(self::NAME, $row->name) !== 1) {
                $this->error("Not updating [{$row->name}]: an extension name this does not write into SQL.");

                return self::FAILURE;
            }

            $db->statement("ALTER EXTENSION {$row->name} UPDATE");
            $this->info("{$row->name} {$row->installed} → {$row->offered}");
        }

        return self::SUCCESS;
    }
}
