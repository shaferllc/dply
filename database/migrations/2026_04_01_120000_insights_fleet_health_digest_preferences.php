<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insight_health_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->json('counts')->nullable();
            $table->timestampTz('captured_at');
            $table->timestamps();

            $table->index(['server_id', 'captured_at']);
        });

        Schema::create('insight_digest_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insight_finding_id')->constrained('insight_findings')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique('insight_finding_id');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->json('insights_preferences')->nullable();
        });

        Schema::table('insight_findings', function (Blueprint $table) {
            $table->json('correlation')->nullable()->after('meta');
            $table->foreignUlid('team_id')->nullable()->after('site_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('insight_findings', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropColumn(['team_id', 'correlation']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('insights_preferences');
        });

        Schema::dropIfExists('insight_digest_queue');
        Schema::dropIfExists('insight_health_snapshots');
    }
};
