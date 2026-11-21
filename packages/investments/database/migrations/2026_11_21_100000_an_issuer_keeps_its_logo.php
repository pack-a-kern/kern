<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company logo the same BVB instrument page shows in its head, cached from there with the rest of the
 * profile: where the image is kept on local disk (`logo_path`, on the `issuer_logos` disk), its media type
 * (`logo_mime`), and a hash of its bytes (`logo_etag`) so a refresh only rewrites the file when it changed
 * and the served image can answer a conditional request. Each null for an issuer whose page shows none, or
 * for a profile read before this.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_issuer_profiles', function (Blueprint $table): void {
            $table->string('logo_path')->nullable();
            $table->string('logo_mime', 64)->nullable();
            $table->string('logo_etag', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ext_issuer_profiles', function (Blueprint $table): void {
            $table->dropColumn(['logo_path', 'logo_mime', 'logo_etag']);
        });
    }
};
