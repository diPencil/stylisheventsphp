<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('registrations')) return;

        // Ensure column exists
        if (!Schema::hasColumn('registrations', 'created_by_user_id')) {
            Schema::table('registrations', function (Blueprint $table) {
                $table->unsignedBigInteger('created_by_user_id')->nullable()->after('id');
            });
        }

        $dbName = DB::getDatabaseName();

        // Ensure index exists (named index: fk_registrations_created_by)
        $indexExists = DB::selectOne(
            'SELECT COUNT(1) as c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$dbName, 'registrations', 'fk_registrations_created_by']
        );
        if (empty($indexExists->c)) {
            Schema::table('registrations', function (Blueprint $table) {
                $table->index('created_by_user_id', 'fk_registrations_created_by');
            });
        }

        // Ensure foreign key exists (named constraint: fk_registrations_created_by)
        $fkExists = DB::selectOne(
            'SELECT COUNT(1) as c FROM information_schema.key_column_usage WHERE table_schema = ? AND table_name = ? AND constraint_name = ? AND referenced_table_name IS NOT NULL',
            [$dbName, 'registrations', 'fk_registrations_created_by']
        );
        if (empty($fkExists->c)) {
            Schema::table('registrations', function (Blueprint $table) {
                $table->foreign('created_by_user_id', 'fk_registrations_created_by')
                      ->references('id')
                      ->on('users')
                      ->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        // This migration reconciles a pre-existing column/index/foreign key into source control.
        // Rolling back must NOT remove production runtime objects that may have existed before this migration was introduced.
        // To protect production, the down() is intentionally a NO-OP. Manually remove objects only in disposable test environments.
        return;
    }
};
