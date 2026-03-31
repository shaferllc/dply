<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 64)->nullable()->unique()->after('remember_token');
            $table->foreignUlid('referred_by_user_id')->nullable()->after('referral_code')->constrained('users')->nullOnDelete();
            $table->timestamp('referral_converted_at')->nullable()->after('referred_by_user_id');
        });

        $ids = DB::table('users')->whereNull('referral_code')->pluck('id');
        foreach ($ids as $id) {
            do {
                $code = Str::lower(Str::random(20));
            } while (DB::table('users')->where('referral_code', $code)->exists());

            DB::table('users')->where('id', $id)->update(['referral_code' => $code]);
        }

        Schema::create('referral_rewards', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('referred_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('referrer_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->unsignedInteger('bonus_credit_cents')->default(0);
            $table->string('stripe_balance_transaction_id')->nullable();
            $table->timestamps();

            $table->unique('referred_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['referred_by_user_id']);
            $table->dropColumn(['referral_code', 'referred_by_user_id', 'referral_converted_at']);
        });
    }
};
