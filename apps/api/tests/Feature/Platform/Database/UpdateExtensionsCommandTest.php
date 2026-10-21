<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * `kern:db:update-extensions`, which `migrate` runs after every migration so an extension's objects
 * follow its image — pgvector 0.8.6 to 0.8.7 was the first.
 *
 * On Postgres it is shown an extension that is behind: `hstore`, which the application does not use,
 * created at an old version its image can still build, so nothing the application relies on is touched.
 */

it('has nothing to update on a database with no extensions', function (): void {
    if (DB::connection()->getDriverName() === 'pgsql') {
        $this->markTestSkipped('This lane is Postgres.');
    }

    expect(Artisan::call('kern:db:update-extensions'))->toBe(0)
        ->and(Artisan::output())->toContain('No extensions');
});

describe('on Postgres', function (): void {
    beforeEach(function (): void {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires a real Postgres connection (DB_CONNECTION=pgsql).');
        }
    });

    afterEach(function (): void {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP EXTENSION IF EXISTS hstore');
        }
    });

    it('brings an extension that is behind up to the version its image ships', function (): void {
        DB::statement("CREATE EXTENSION hstore VERSION '1.4'");

        /** @var object{offered: string} $available */
        $available = DB::selectOne("select default_version as offered from pg_available_extensions where name = 'hstore'");

        expect(Artisan::call('kern:db:update-extensions'))->toBe(0)
            ->and(Artisan::output())->toContain("hstore 1.4 → {$available->offered}");

        /** @var object{installed: string} $row */
        $row = DB::selectOne("select extversion as installed from pg_extension where extname = 'hstore'");

        expect($row->installed)->toBe($available->offered);
    });

    it('leaves everything alone once nothing is behind', function (): void {
        Artisan::call('kern:db:update-extensions');

        expect(Artisan::call('kern:db:update-extensions'))->toBe(0)
            ->and(Artisan::output())->toContain('Every extension is at the version its image ships.');
    });
});
