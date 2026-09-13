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
            foreach ([
                'agenda_ar',
                'agenda_en',
                'checkin_notes_ar',
                'checkin_notes_en',
                'ticket_terms_ar',
                'ticket_terms_en',
            ] as $column) {
                if (!Schema::hasColumn('events', $column)) {
                    $table->text($column)->nullable()->after('description_ar');
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            foreach ([
                'ticket_terms_en',
                'ticket_terms_ar',
                'checkin_notes_en',
                'checkin_notes_ar',
                'agenda_en',
                'agenda_ar',
            ] as $column) {
                if (Schema::hasColumn('events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
