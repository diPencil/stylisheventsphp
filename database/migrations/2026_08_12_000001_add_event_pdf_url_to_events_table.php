<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('events', 'event_pdf_url')) {
            Schema::table('events', function (Blueprint $table) {
                $table->string('event_pdf_url', 500)->nullable()->after('event_details_image_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('events', 'event_pdf_url')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropColumn('event_pdf_url');
            });
        }
    }
};
