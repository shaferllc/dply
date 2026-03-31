<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('country_code', 2)->nullable()->after('email');
            $table->string('locale', 12)->nullable()->after('country_code');
            $table->string('timezone', 64)->nullable()->after('locale');
            $table->string('invoice_email')->nullable()->after('timezone');
            $table->string('vat_number', 64)->nullable()->after('invoice_email');
            $table->string('billing_currency', 3)->nullable()->after('vat_number');
            $table->text('billing_details')->nullable()->after('billing_currency');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'country_code',
                'locale',
                'timezone',
                'invoice_email',
                'vat_number',
                'billing_currency',
                'billing_details',
            ]);
        });
    }
};
