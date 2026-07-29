<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debug_allowed_ips', function (Blueprint $table): void {
            $table->id();
            $table->string('ip'); // IPv4, IPv6, or CIDR
            $table->string('label')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
            $table->unique('ip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debug_allowed_ips');
    }
};
