<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * The refusals of `kern:db:grant-app-role`, every one of them reached before the password is read.
 *
 * The grant itself reads its password from PHP's `STDIN`, which under the test runner is the runner's
 * own and never ends — the same reason `CreateAdministratorCommandTest` leaves `--stdin` out. It is
 * verified against the running database instead: `make migrate`, then the application's role asked
 * to alter, disable, update, delete and run a program, and refused each time (the findings list's
 * status line for this item records what was run).
 */
it('refuses a connection that is not Postgres', function (): void {
    if (DB::connection()->getDriverName() === 'pgsql') {
        $this->markTestSkipped('This lane is Postgres.');
    }

    expect(Artisan::call('kern:db:grant-app-role', ['role' => 'kern_app', '--stdin' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('Postgres');
});

describe('on Postgres', function (): void {
    beforeEach(function (): void {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires a real Postgres connection (DB_CONNECTION=pgsql).');
        }
    });

    it('refuses a role name it would have to quote', function (string $name): void {
        expect(Artisan::call('kern:db:grant-app-role', ['role' => $name, '--stdin' => true]))->toBe(1);
    })->with(['an uppercase letter' => 'Kern_app', 'a quote' => 'kern"app', 'a leading digit' => '1kern']);

    it('refuses to make the owner the application role', function (): void {
        // Resetting the owner's attributes to the application's would take its own away.
        /** @var object{name: string} $row */
        $row = DB::selectOne('select current_user as name');
        $owner = $row->name;

        expect(Artisan::call('kern:db:grant-app-role', ['role' => $owner, '--stdin' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('the owner');
    });

    it('will not take the password as anything but standard input', function (): void {
        // An argument would be on the command line, where `ps` shows it to every account on the host.
        expect(Artisan::call('kern:db:grant-app-role', ['role' => 'kern_app_never_created']))->toBe(1)
            ->and(Artisan::output())->toContain('--stdin');

        /** @var object{n: int} $count */
        $count = DB::selectOne("select count(*) as n from pg_roles where rolname = 'kern_app_never_created'");

        expect($count->n)->toBe(0);
    });
});
