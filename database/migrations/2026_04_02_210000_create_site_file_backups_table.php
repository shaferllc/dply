<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_file_backups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('disk_path', 512)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_file_backups');
    }
};
