<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serverless_function_deployments', function (Blueprint $table) {
            $table->id();
            $table->string('function_name');
            $table->string('runtime');
            $table->string('artifact_path');
            $table->string('status', 32)->default('queued');
            $table->string('trigger', 32);
            $table->text('provisioner_output')->nullable();
            $table->string('revision_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serverless_function_deployments');
    }
};
