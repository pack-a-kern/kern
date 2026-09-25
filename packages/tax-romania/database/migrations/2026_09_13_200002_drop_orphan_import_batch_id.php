<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes `financial_transactions.import_batch_id`.
 *
 * It was schema laid down ahead of a statement importer: a uuid meant to say "this row came from
 * import #5". There is no `import_batches` table for it to point at, nothing has ever written it,
 * and nothing reads it — an orphan foreign key with no foreign side.
 *
 * When the importer is built it needs a real batch record anyway (which file, which source, when,
 * how many rows, which institution), and that is the point at which this column earns its place
 * back — pointing at something.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->dropColumn('import_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->uuid('import_batch_id')->nullable();
        });
    }
};
