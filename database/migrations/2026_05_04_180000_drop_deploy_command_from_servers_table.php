<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the legacy `deploy_command` column. The web /deploy page that
     * edited this field has been merged into /run, marketplace items
     * typed RECIPE_DEPLOY_COMMAND now land as ServerRecipe rows, and
     * the dead `POST /api/servers/{id}/deploy` endpoint has been
     * removed. Existing values are dropped per operator decision —
     * clean break, no data preservation.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn('deploy_command');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            // Restoring the column would not restore the dropped values;
            // this is intentionally lossy. The down migration exists
            // only to allow rollback in environments where Laravel's
            // migration framework requires it.
            $table->text('deploy_command')->nullable();
        });
    }
};
