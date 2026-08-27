<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contact_inquiries')) {
            if (!Schema::hasColumn('contact_inquiries', 'phone_country_code')) {
                Schema::table('contact_inquiries', function (Blueprint $table) {
                    $table->string('phone_country_code')->nullable();
                });
            }
            if (!Schema::hasColumn('contact_inquiries', 'phone_number')) {
                Schema::table('contact_inquiries', function (Blueprint $table) {
                    $table->string('phone_number')->nullable();
                });
            }
            if (!Schema::hasColumn('contact_inquiries', 'expected_attendees')) {
                Schema::table('contact_inquiries', function (Blueprint $table) {
                    $table->unsignedInteger('expected_attendees')->nullable();
                });
            }
            if (!Schema::hasColumn('contact_inquiries', 'resolved_at')) {
                Schema::table('contact_inquiries', function (Blueprint $table) {
                    $table->timestamp('resolved_at')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        // noop down for safety
    }
};
