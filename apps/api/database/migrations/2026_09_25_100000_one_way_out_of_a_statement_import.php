<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Undo goes; deleting an import is the one way out of it.
 *
 * Two controls answered one intention — "this import was a mistake" — and the order between them
 * mattered: undo took the transactions out and left an import that could not be opened, could not be
 * imported again, and said nothing about itself. Delete now removes the transactions, the rows, the
 * import and the statement file in one act, and the screen says so before it happens.
 *
 * So `UNDONE` is a state nothing can reach any more. The imports already in it are exactly what the
 * new delete removes — their transactions are long gone — so they go, and the column that dated the
 * undoing goes with them. Their statement files stay in the documents list, where a person can
 * delete them: this migration has no key to the object store and will not orphan bytes by guessing.
 *
 * `WalletAuditEvent::STATEMENT_IMPORT_UNDONE` stays. The chain is append-only and the entries it
 * wrote are still there; an enum that no longer named them would leave that history unreadable.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::table('statement_imports')->where('status', 'UNDONE')->delete();

        Schema::table('statement_imports', function (Blueprint $table): void {
            $table->dropColumn('undone_at');
        });
    }

    public function down(): void
    {
        Schema::table('statement_imports', function (Blueprint $table): void {
            $table->timestampTz('undone_at')->nullable();
        });
    }
};
