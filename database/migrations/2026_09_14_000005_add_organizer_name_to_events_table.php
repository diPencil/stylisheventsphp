<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('events') || Schema::hasColumn('events', 'organizer_name')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            $table->string('organizer_name')->nullable()->after('organizer_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('events') || !Schema::hasColumn('events', 'organizer_name')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('organizer_name');
        });
    }
};
