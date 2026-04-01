<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firewall_rule_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $this->addOrganizationReference($table);
            $table->foreignUlid('server_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('description', 500)->nullable();
            $table->json('rules');
            $table->timestamps();

            $table->index(['organization_id', 'server_id']);
        });

        Schema::create('server_firewall_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 200)->nullable();
            $table->json('rules');
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
        });

        Schema::create('server_firewall_audit_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('api_token_id')->nullable()->constrained('api_tokens')->nullOnDelete();
            $table->string('event', 64);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
        });

        Schema::table('server_firewall_rules', function (Blueprint $table) {
            $table->unsignedSmallInteger('port')->nullable()->change();
            $table->string('protocol', 16)->default('tcp')->change();
        });
    }

    public function down(): void
    {
        Schema::table('server_firewall_rules', function (Blueprint $table) {
            $table->unsignedSmallInteger('port')->nullable(false)->change();
            $table->string('protocol', 8)->default('tcp')->change();
        });

        Schema::dropIfExists('server_firewall_audit_events');
        Schema::dropIfExists('server_firewall_snapshots');
        Schema::dropIfExists('firewall_rule_templates');
    }

    private function addOrganizationReference(Blueprint $table): void
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            return;
        }

        $column = DB::table('information_schema.columns')
            ->select(['column_type', 'character_set_name', 'collation_name'])
            ->where('table_schema', $connection->getDatabaseName())
            ->where('table_name', 'organizations')
            ->where('column_name', 'id')
            ->first();

        if (! $column) {
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            return;
        }

        $columnType = strtolower((string) $column->column_type);

        if (str_contains($columnType, 'bigint')) {
            $table->unsignedBigInteger('organization_id');
        } elseif (str_contains($columnType, 'varchar(')) {
            preg_match('/varchar\((\d+)\)/', $columnType, $matches);
            $length = isset($matches[1]) ? (int) $matches[1] : 26;
            $table->string('organization_id', $length);
        } else {
            $table->char('organization_id', 26);
        }

        if (isset($column->character_set_name) && is_string($column->character_set_name)) {
            $table->charset($column->character_set_name);
        }

        if (isset($column->collation_name) && is_string($column->collation_name)) {
            $table->collation($column->collation_name);
        }

        $table->foreign('organization_id')
            ->references('id')
            ->on('organizations')
            ->cascadeOnDelete();
    }
};
