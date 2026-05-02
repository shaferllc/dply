<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_processes', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('site_id', 26);
            $table->string('type', 32);
            $table->string('name', 64);
            $table->text('command')->nullable();
            $table->unsignedSmallInteger('scale')->default(1);
            $table->json('env_vars')->nullable();
            $table->string('working_directory', 512)->nullable();
            $table->string('user', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->unique(['site_id', 'name']);
            $table->index(['site_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_processes');
    }
};
