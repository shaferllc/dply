<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users use ULIDs (26-char strings); Laragear's default morph uses bigint ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webauthn_credentials', function (Blueprint $table) {
            $table->string('authenticatable_id', 26)->change();
        });
    }

    public function down(): void
    {
        Schema::table('webauthn_credentials', function (Blueprint $table) {
            $table->unsignedBigInteger('authenticatable_id')->change();
        });
    }
};
