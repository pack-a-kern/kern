<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Crypto\EncryptedColumnsAreNotRenamedTest;

use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Crypto\Casts\Encrypted;
use RuntimeException;
use SplFileInfo;

/**
 * No migration renames a table or a column that holds an encrypted value.
 *
 * ## What breaks, and why nothing catches it
 *
 * Every field ciphertext is sealed against `subject_id | table | field | record_uuid`
 * ({@see \Kern\Platform\Crypto\AssociatedData}). All four are inputs to the decryption rather than
 * labels attached to it, so changing any one of them does not produce wrong data — it produces
 * `EnvelopeException` on every row written before the change.
 *
 * Which makes two of the most ordinary migrations there are fatal:
 *
 *  - `Schema::rename()` on a table holding an encrypted column — every row in it dies.
 *  - `renameColumn()` on the encrypted column itself — the `field` segment changes, same result.
 *
 * And the suite is structurally incapable of noticing. Tests run `migrate:fresh`, so every row they
 * write is written *after* the rename and its binding is consistent; the damage only exists on a
 * database that had rows beforehand. A green test run proves nothing about it, and always will.
 *
 * It has happened twice. The migration that gave `counterparty_rules` its name column had to delete
 * every existing row, because the ciphertext it wanted to copy out of `financial_transactions` is
 * bound to that table and that row and would not decrypt here. And `counterparty_rules` was later
 * renamed to `counterparties`, which ran clean, passed every test, and left two of two hundred and
 * sixty-nine rows unreadable — found by running a search against a real database, not by anything
 * mechanical.
 *
 * ## What this checks
 *
 * The encrypted `(table, column)` pairs build themselves: a model casting to {@see Encrypted} knows
 * its own table and which columns it seals. The migrations are then read as text and refused if they
 * rename either.
 *
 * A source scan, so its limits are worth stating plainly: it sees `Schema::rename('x', 'y')` and
 * `->renameColumn('a', 'b')` written literally, and would miss a rename built from a variable or run
 * as raw SQL. That is the common case covered, not every case.
 *
 * ## When a rename is legitimate
 *
 * When the migration re-encrypts the rows through the model layer first, or deletes them knowing
 * what that costs. Then name it in `ALLOWED` with the reason — the same shape of exception
 * `Tests\Feature\Ui\ControlHeightTest` makes for `.ctl-icon`, and for the same purpose: a second
 * exception should be a decision somebody wrote down.
 */

/**
 * Migrations that rename something encrypted on purpose, and why that was safe.
 *
 * A function rather than a constant so that it stays typed while it is empty: a `const [] `narrows
 * to `array{}`, and every lookup against it then reads as dead code.
 *
 * @return array<string, string> migration filename => why the rename could not lose a row
 */
function allowed(): array
{
    return [];
}

/**
 * Every `table.column` that holds a ciphertext, gathered from the models that seal them.
 *
 * @return array{tables: list<string>, columns: list<string>}
 */
function encryptedSchema(): array
{
    $tables = [];
    $columns = [];

    foreach (modelFiles() as $file) {
        $class = classIn($file);

        if ($class === null || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        $model = new $class();
        $sealed = array_keys(array_filter(
            $model->getCasts(),
            static fn (mixed $cast): bool => $cast === Encrypted::class,
        ));

        if ($sealed === []) {
            continue;
        }

        $tables[] = $model->getTable();

        foreach ($sealed as $column) {
            $columns[] = (string) $column;
        }
    }

    return ['tables' => array_values(array_unique($tables)), 'columns' => array_values(array_unique($columns))];
}

/** @return list<SplFileInfo> */
function modelFiles(): array
{
    $files = [];

    foreach (packageRoots() as $root) {
        $directory = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src'));

        foreach ($directory as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php' && str_contains($file->getPath(), 'Models')) {
                $files[] = $file;
            }
        }
    }

    return $files;
}

/** @return list<SplFileInfo> */
function migrationFiles(): array
{
    $files = [];

    foreach (packageRoots() as $root) {
        $directory = $root . '/database/migrations';

        if (! is_dir($directory)) {
            continue;
        }

        foreach ((array) glob($directory . '/*.php') as $path) {
            if (is_string($path)) {
                $files[] = new SplFileInfo($path);
            }
        }
    }

    return $files;
}

/**
 * The local packages, from wherever this is run.
 *
 * `../../packages` relative to the application root resolves to `packages/` on a host checkout and
 * to `/var/packages` inside the container, which mounts them as a sibling — the same two spellings
 * `phpstan.neon` and `deptrac.php` each have to know about.
 *
 * @return list<string>
 */
function packageRoots(): array
{
    $base = realpath(dirname(__DIR__, 4) . '/../../packages');

    if ($base === false) {
        throw new RuntimeException('Cannot find the packages directory from ' . dirname(__DIR__, 4) . '.');
    }

    $roots = [];

    foreach ((array) glob($base . '/*') as $path) {
        if (is_string($path) && is_dir($path)) {
            $roots[] = $path;
        }
    }

    return $roots;
}

function classIn(SplFileInfo $file): ?string
{
    $source = (string) file_get_contents($file->getPathname());

    if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
        return null;
    }

    if (preg_match('/^(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/m', $source, $class) !== 1) {
        return null;
    }

    $name = trim($namespace[1]) . '\\' . $class[1];

    return class_exists($name) ? $name : null;
}

it('finds the encrypted columns at all, so a passing run means something', function (): void {
    // Without this, a models directory that moved would leave every assertion below comparing
    // against an empty list and reporting success.
    $schema = encryptedSchema();

    expect($schema['tables'])->toContain('financial_transactions')
        ->and($schema['columns'])->toContain('counterparty_ciphertext');
});

it('finds the migrations at all', function (): void {
    expect(migrationFiles())->not->toBeEmpty();
});

it('never renames a table that holds an encrypted column', function (): void {
    $tables = encryptedSchema()['tables'];
    $renames = [];

    foreach (migrationFiles() as $file) {
        if (array_key_exists($file->getFilename(), allowed())) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        preg_match_all('/Schema::rename\(\s*[\'"]([^\'"]+)[\'"]/', $source, $found);

        foreach ($found[1] as $table) {
            if (in_array($table, $tables, true)) {
                $renames[] = "{$file->getFilename()} renames table {$table}";
            }
        }
    }

    // Every ciphertext in that table is sealed against its name. Renaming it makes every row
    // already stored unreadable, and no test run against a fresh database can show that.
    expect($renames)->toBe([]);
});

it('never renames a column that holds an encrypted value', function (): void {
    $columns = encryptedSchema()['columns'];
    $renames = [];

    foreach (migrationFiles() as $file) {
        if (array_key_exists($file->getFilename(), allowed())) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        preg_match_all('/renameColumn\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $source, $found, PREG_SET_ORDER);

        foreach ($found as $match) {
            foreach ([$match[1], $match[2]] as $column) {
                if (in_array($column, $columns, true)) {
                    $renames[] = "{$file->getFilename()} renames column {$column}";
                }
            }
        }
    }

    // The `field` segment of the associated data is the column name, so this is the same failure
    // one level down — and an easier mistake to make, because a column rename looks local.
    expect(array_values(array_unique($renames)))->toBe([]);
});

it('names a reason for every rename it has been told to allow', function (): void {
    // So the allowlist cannot grow silently: an entry with no explanation is the next person
    // assuming somebody checked.
    $unexplained = [];

    foreach (allowed() as $migration => $reason) {
        if (trim($reason) === '') {
            $unexplained[] = $migration;
        }
    }

    expect($unexplained)->toBe([]);
});
