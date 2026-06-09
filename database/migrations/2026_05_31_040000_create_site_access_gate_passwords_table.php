<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_access_gate_passwords', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('label', 64);
            $table->string('password_salt', 64);
            $table->string('password_verifier', 64);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('pending_removal_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'label']);
        });

        if (! Schema::hasTable('site_access_gates')) {
            return;
        }

        $legacy = DB::table('site_access_gates')
            ->where('method', 'form_password')
            ->whereNotNull('password_verifier')
            ->where('password_verifier', '!=', '')
            ->get(['site_id', 'password_salt', 'password_verifier', 'created_at', 'updated_at']);

        foreach ($legacy as $row) {
            DB::table('site_access_gate_passwords')->insert([
                'id' => (string) Str::ulid(),
                'site_id' => $row->site_id,
                'label' => 'Default',
                'password_salt' => $row->password_salt,
                'password_verifier' => $row->password_verifier,
                'sort_order' => 0,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_access_gate_passwords');
    }
};
