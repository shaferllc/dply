<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users use ULIDs; Laragear's default migration uses unsignedBigInteger for the morph id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('webauthn_credentials')) {
            return;
        }

        Schema::table('webauthn_credentials', function (Blueprint $table) {
            $table->string('authenticatable_id', 36)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('webauthn_credentials')) {
            return;
        }

        Schema::table('webauthn_credentials', function (Blueprint $table) {
            $table->unsignedBigInteger('authenticatable_id')->change();
        });
    }
};
