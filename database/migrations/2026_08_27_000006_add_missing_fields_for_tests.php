<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add template_key to certificates
        if (!Schema::hasColumn('certificates', 'template_key')) {
            Schema::table('certificates', function (Blueprint $table) {
                $table->string('template_key')->nullable()->after('certificate_number');
            });
        }

        // Add card_number and attendee_id and file_url to event_cards
        if (!Schema::hasColumn('event_cards', 'card_number') || !Schema::hasColumn('event_cards', 'attendee_id') || !Schema::hasColumn('event_cards', 'file_url')) {
            Schema::table('event_cards', function (Blueprint $table) {
                if (!Schema::hasColumn('event_cards', 'attendee_id')) {
                    $table->unsignedBigInteger('attendee_id')->nullable()->after('event_id');
                }
                if (!Schema::hasColumn('event_cards', 'card_number')) {
                    $table->string('card_number')->nullable()->after('attendee_id');
                }
                if (!Schema::hasColumn('event_cards', 'file_url')) {
                    $table->string('file_url')->nullable()->after('card_number');
                }
            });
        }

        // Add customer_id to orders if missing
        if (!Schema::hasColumn('orders', 'customer_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('id');
            });
        }
    }

    public function down(): void
    {
        // noop down - keep migrations idempotent for safety in local tests
    }
};
