<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_bindings', function (Blueprint $table): void {
            // Operator customization of the keys this binding injects:
            //   { aliases: { CANONICAL: [ALIAS, …] }, overrides: { KEY: value } }
            //
            // Deliberately NOT part of `config`:
            //   1. An override of DB_PASSWORD (or DATABASE_URL, which embeds it)
            //      is a credential — this column is encrypted like injected_env,
            //      whereas `config` is plaintext json.
            //   2. The attach/edit path rebuilds `config` wholesale and saves via
            //      forceFill($attributes); a column absent from $attributes is
            //      left untouched, so customization survives a re-point for free.
            $table->text('env_customization')->nullable()->after('config');
        });
    }

    public function down(): void
    {
        Schema::table('site_bindings', function (Blueprint $table): void {
            $table->dropColumn('env_customization');
        });
    }
};
