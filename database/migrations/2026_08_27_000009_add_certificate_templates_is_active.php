<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('certificate_templates') && !Schema::hasColumn('certificate_templates', 'is_active')) {
            Schema::table('certificate_templates', function (Blueprint $table) {
                $table->boolean('is_active')->default(1)->after('is_default');
            });
        }
    }

    public function down(): void
    {
        // noop down for safety
    }
};
