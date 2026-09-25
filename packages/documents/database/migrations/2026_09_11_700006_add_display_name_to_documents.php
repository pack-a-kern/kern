<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The name a person chose for a document, as opposed to the one their phone chose.
 *
 * Null means "never renamed", and the screen shows a name derived from the content hash instead
 * (`Kern\Documents\DocumentDisplayName`) — so this column stays empty for every document
 * nobody has bothered to name, and there is nothing to backfill for documents that predate it.
 *
 * Encrypted, like `original_filename_ciphertext` beside it: a name someone gives their own invoice
 * is as revealing as the invoice ("Contract chirie Popescu", "Decizie ANAF CASS 2023").
 *
 * `original_filename_ciphertext` is kept rather than replaced. What a file was actually called when
 * it arrived is provenance, and this platform's whole premise is that provenance survives — it
 * simply stops being the label on the list.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->text('display_name_ciphertext')->nullable()->after('original_filename_ciphertext');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('display_name_ciphertext');
        });
    }
};
