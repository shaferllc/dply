<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The recognized remediation for an error, matched at capture time against the
 * remediations catalog. Lets the Errors view show a "Fix" button and a count of
 * fixable errors without re-matching every render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('error_events', function (Blueprint $table) {
            $table->string('remediation_code')->nullable()->after('category')->index();
        });
    }

    public function down(): void
    {
        Schema::table('error_events', function (Blueprint $table) {
            $table->dropColumn('remediation_code');
        });
    }
};
