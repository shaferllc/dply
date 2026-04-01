<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coming_soon_signups')) {
            return;
        }

        Schema::create('coming_soon_signups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('email', 254)->unique();
            $table->string('source', 120)->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coming_soon_signups');
    }
};
