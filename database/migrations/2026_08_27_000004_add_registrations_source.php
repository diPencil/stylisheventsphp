<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('registrations') && !Schema::hasColumn('registrations', 'source')) {
            Schema::table('registrations', function (Blueprint $table) {
                $table->string('source')->nullable()->after('order_id');
            });
        }
    }

    public function down(): void
    {
        return;
    }
};
