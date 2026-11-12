<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A taxpayer is in use or closed — nothing between. `SUSPENDED` was a second name for closed: both were
 * readable, neither writable, and either could be brought back. Any suspended one is closed.
 *
 * The check constraint is Postgres's: Laravel cannot change an enum column there (it writes the check
 * into `alter column … type`, which Postgres refuses), so it is replaced by name. SQLite builds the
 * schema anew for every test and the model's enum refuses anything else.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::table('tax_subjects')->where('status', 'SUSPENDED')->update(['status' => 'CLOSED']);

        $this->allow(['ACTIVE', 'CLOSED']);
    }

    public function down(): void
    {
        $this->allow(['ACTIVE', 'SUSPENDED', 'CLOSED']);
    }

    /** @param list<string> $statuses */
    private function allow(array $statuses): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $quoted = implode(', ', array_map(static fn (string $status): string => "'{$status}'", $statuses));

        DB::statement('ALTER TABLE tax_subjects DROP CONSTRAINT tax_subjects_status_check');
        DB::statement("ALTER TABLE tax_subjects ADD CONSTRAINT tax_subjects_status_check CHECK (status IN ({$quoted}))");
    }
};
