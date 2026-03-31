<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serverless_function_deployments', function (Blueprint $table) {
            $table->foreignId('serverless_project_id')
                ->nullable()
                ->after('id')
                ->constrained('serverless_projects')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('serverless_function_deployments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('serverless_project_id');
        });
    }
};
