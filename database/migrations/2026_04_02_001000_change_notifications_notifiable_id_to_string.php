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

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS notifications_notifiable_type_notifiable_id_index');
            DB::statement('ALTER TABLE notifications ALTER COLUMN notifiable_id TYPE varchar(255) USING notifiable_id::text');
            DB::statement('CREATE INDEX notifications_notifiable_type_notifiable_id_index ON notifications (notifiable_type, notifiable_id)');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS notifications_notifiable_type_notifiable_id_index');
            DB::statement('ALTER TABLE notifications RENAME TO notifications_old');
            DB::statement(<<<'SQL'
CREATE TABLE notifications (
    id UUID NOT NULL,
    type VARCHAR(255) NOT NULL,
    notifiable_type VARCHAR(255) NOT NULL,
    notifiable_id VARCHAR(255) NOT NULL,
    data TEXT NOT NULL,
    read_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    CONSTRAINT notifications_pkey PRIMARY KEY (id)
)
SQL);
            DB::statement(<<<'SQL'
INSERT INTO notifications (id, type, notifiable_type, notifiable_id, data, read_at, created_at, updated_at)
SELECT id, type, notifiable_type, CAST(notifiable_id AS TEXT), data, read_at, created_at, updated_at
FROM notifications_old
SQL);
            DB::statement('DROP TABLE notifications_old');
            DB::statement('CREATE INDEX notifications_notifiable_type_notifiable_id_index ON notifications (notifiable_type, notifiable_id)');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS notifications_notifiable_type_notifiable_id_index');
            DB::statement('ALTER TABLE notifications ALTER COLUMN notifiable_id TYPE bigint USING NULLIF(notifiable_id, \'\')::bigint');
            DB::statement('CREATE INDEX notifications_notifiable_type_notifiable_id_index ON notifications (notifiable_type, notifiable_id)');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS notifications_notifiable_type_notifiable_id_index');
            DB::statement('ALTER TABLE notifications RENAME TO notifications_old');
            DB::statement(<<<'SQL'
CREATE TABLE notifications (
    id UUID NOT NULL,
    type VARCHAR(255) NOT NULL,
    notifiable_type VARCHAR(255) NOT NULL,
    notifiable_id BIGINT NOT NULL,
    data TEXT NOT NULL,
    read_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    CONSTRAINT notifications_pkey PRIMARY KEY (id)
)
SQL);
            DB::statement(<<<'SQL'
INSERT INTO notifications (id, type, notifiable_type, notifiable_id, data, read_at, created_at, updated_at)
SELECT id, type, notifiable_type, CAST(notifiable_id AS INTEGER), data, read_at, created_at, updated_at
FROM notifications_old
SQL);
            DB::statement('DROP TABLE notifications_old');
            DB::statement('CREATE INDEX notifications_notifiable_type_notifiable_id_index ON notifications (notifiable_type, notifiable_id)');
        }
    }
};
