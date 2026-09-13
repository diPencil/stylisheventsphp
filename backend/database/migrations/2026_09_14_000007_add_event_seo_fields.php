<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'seo_title')) {
                $table->string('seo_title')->nullable()->after('google_maps_url');
            }
            if (!Schema::hasColumn('events', 'seo_description')) {
                $table->text('seo_description')->nullable()->after('seo_title');
            }
            if (!Schema::hasColumn('events', 'seo_keywords')) {
                $table->text('seo_keywords')->nullable()->after('seo_description');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'seo_keywords')) {
                $table->dropColumn('seo_keywords');
            }
            if (Schema::hasColumn('events', 'seo_description')) {
                $table->dropColumn('seo_description');
            }
            if (Schema::hasColumn('events', 'seo_title')) {
                $table->dropColumn('seo_title');
            }
        });
    }
};
