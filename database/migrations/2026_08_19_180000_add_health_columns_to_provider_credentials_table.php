<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_credentials', function (Blueprint $table): void {
            $table->timestamp('last_validated_at')->nullable()->after('credentials');
            $table->text('validation_error')->nullable()->after('last_validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('provider_credentials', function (Blueprint $table): void {
            $table->dropColumn(['last_validated_at', 'validation_error']);
        });
    }
};
