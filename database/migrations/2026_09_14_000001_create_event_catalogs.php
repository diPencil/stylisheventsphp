<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('event_catalogs')) {
            Schema::create('event_catalogs', function (Blueprint $table) {
                $table->id();
                $table->string('name_en');
                $table->string('name_ar')->nullable();
                $table->boolean('is_active')->default(1);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['name_en']);
            });
        }

        if (Schema::hasTable('events') && !Schema::hasColumn('events', 'catalogs_json')) {
            Schema::table('events', function (Blueprint $table) {
                $table->text('catalogs_json')->nullable()->after('gallery_json');
            });
        }
    }

    public function down(): void
    {
        return;
    }
};
