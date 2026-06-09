<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_deployments', function (Blueprint $table): void {
            $table->string('storage_prefix')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('edge_deployments', function (Blueprint $table): void {
            $table->string('storage_prefix')->nullable(false)->change();
        });
    }
};
