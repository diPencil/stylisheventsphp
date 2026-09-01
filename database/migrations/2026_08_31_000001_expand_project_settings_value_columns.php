<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('project_settings')) {
            return;
        }

        Schema::table('project_settings', function (Blueprint $table) {
            $table->longText('setting_value')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('project_settings')) {
            return;
        }

        $maxLength = DB::table('project_settings')->max(DB::raw(
            'COALESCE(CHAR_LENGTH(setting_value), 0)'
        ));

        if ($maxLength > 255) {
            throw new RuntimeException('Cannot shrink project_settings values to varchar(255) without truncating existing data.');
        }

        Schema::table('project_settings', function (Blueprint $table) {
            $table->string('setting_value')->nullable()->change();
        });
    }
};
