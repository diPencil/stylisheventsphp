<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendees')) {
            if (!Schema::hasColumn('attendees', 'order_id')) {
                Schema::table('attendees', function (Blueprint $table) {
                    $table->unsignedBigInteger('order_id')->nullable()->after('id')->index();
                });
            }
            if (!Schema::hasColumn('attendees', 'attendee_number')) {
                Schema::table('attendees', function (Blueprint $table) {
                    $table->string('attendee_number')->nullable()->after('ticket_type_id');
                });
            }
            if (!Schema::hasColumn('attendees', 'phone')) {
                Schema::table('attendees', function (Blueprint $table) {
                    $table->string('phone')->nullable()->after('email');
                });
            }
            if (!Schema::hasColumn('attendees', 'qr_token')) {
                Schema::table('attendees', function (Blueprint $table) {
                    $table->string('qr_token')->nullable()->after('phone');
                });
            }
        }
    }

    public function down(): void
    {
        return;
    }
};
