<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver !== 'pgsql') {
            throw new RuntimeException('This migration supports PostgreSQL only.');
        }

        DB::statement('DROP INDEX IF EXISTS notifications_notifiable_type_notifiable_id_index');
        DB::statement('ALTER TABLE notifications ALTER COLUMN notifiable_id TYPE varchar(255) USING notifiable_id::text');
        DB::statement('CREATE INDEX notifications_notifiable_type_notifiable_id_index ON notifications (notifiable_type, notifiable_id)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver !== 'pgsql') {
            throw new RuntimeException('This migration supports PostgreSQL only.');
        }

        DB::statement('DROP INDEX IF EXISTS notifications_notifiable_type_notifiable_id_index');
        DB::statement('ALTER TABLE notifications ALTER COLUMN notifiable_id TYPE bigint USING NULLIF(notifiable_id, \'\')::bigint');
        DB::statement('CREATE INDEX notifications_notifiable_type_notifiable_id_index ON notifications (notifiable_type, notifiable_id)');
    }
};
