<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'city_name')) {
                $table->string('city_name')->nullable()->after('venue_id');
            }
            if (!Schema::hasColumn('events', 'venue_name')) {
                $table->string('venue_name')->nullable()->after('city_name');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'venue_name')) {
                $table->dropColumn('venue_name');
            }
            if (Schema::hasColumn('events', 'city_name')) {
                $table->dropColumn('city_name');
            }
        });
    }
};
