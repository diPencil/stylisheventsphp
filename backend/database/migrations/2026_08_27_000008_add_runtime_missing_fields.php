<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure certificate_templates runtime columns exist
        if (Schema::hasTable('certificate_templates')) {
            if (!Schema::hasColumn('certificate_templates', 'template_type')) {
                Schema::table('certificate_templates', function (Blueprint $table) {
                    $table->string('template_type')->nullable()->after('name');
                });
            }
            if (!Schema::hasColumn('certificate_templates', 'template_url')) {
                Schema::table('certificate_templates', function (Blueprint $table) {
                    $table->string('template_url')->nullable()->after('template_type');
                });
            }
            if (!Schema::hasColumn('certificate_templates', 'field_positions_json')) {
                Schema::table('certificate_templates', function (Blueprint $table) {
                    $table->text('field_positions_json')->nullable()->after('template_url');
                });
            }
        }

        // Ensure event_cards.template_key exists (used when inserting/updating cards)
        if (Schema::hasTable('event_cards') && !Schema::hasColumn('event_cards', 'template_key')) {
            Schema::table('event_cards', function (Blueprint $table) {
                $table->string('template_key')->nullable()->after('card_number');
            });
        }
    }

    public function down(): void
    {
        // noop down for local safety
    }
};
