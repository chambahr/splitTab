<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->char('idempotency_fingerprint', 64)->nullable()->after('idempotency_key');
        });
        Schema::table('settlement_transfers', function (Blueprint $table) {
            $table->string('payment_reference', 160)->nullable();
        });

        DB::table('budget_categories')->insertOrIgnore([
            ['name' => 'Accommodation', 'slug' => 'accommodation', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Transport', 'slug' => 'transport', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Food', 'slug' => 'food', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Activities', 'slug' => 'activities', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Shopping', 'slug' => 'shopping', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Emergency', 'slug' => 'emergency', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Other', 'slug' => 'other', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION splittrip_prevent_audit_log_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs are immutable';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER splittrip_audit_logs_immutable
            BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION splittrip_prevent_audit_log_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS splittrip_audit_logs_immutable ON audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS splittrip_prevent_audit_log_mutation()');
        Schema::table('settlement_transfers', fn (Blueprint $table) => $table->dropColumn('payment_reference'));
        Schema::table('expenses', fn (Blueprint $table) => $table->dropColumn('idempotency_fingerprint'));
    }
};
