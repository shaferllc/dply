<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('console_actions', function (Blueprint $table): void {
            // Optional per-dispatch label that overrides the kind's default
            // running/completed/failed copy. Lets a single kind (e.g. webserver_config,
            // dispatched from many UI paths) carry the operator's actual perceived
            // action — "Removing credential" rather than "Applying webserver config".
            // NULL falls back to config('console_actions.kinds.<kind>.<status>').
            $table->string('label', 255)->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('console_actions', function (Blueprint $table): void {
            $table->dropColumn('label');
        });
    }
};
