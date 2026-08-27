<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contact_inquiries')) {
            if (!Schema::hasColumn('contact_inquiries', 'company')) {
                Schema::table('contact_inquiries', function (Blueprint $table) {
                    $table->string('company')->nullable();
                });
            }
            if (!Schema::hasColumn('contact_inquiries', 'preferred_contact_method')) {
                Schema::table('contact_inquiries', function (Blueprint $table) {
                    $table->string('preferred_contact_method')->nullable();
                });
            }
            if (!Schema::hasColumn('contact_inquiries', 'event_date')) {
                Schema::table('contact_inquiries', function (Blueprint $table) {
                    $table->date('event_date')->nullable();
                });
            }
            if (!Schema::hasColumn('contact_inquiries', 'event_city')) {
                Schema::table('contact_inquiries', function (Blueprint $table) {
                    $table->string('event_city')->nullable();
                });
            }
            if (!Schema::hasColumn('contact_inquiries', 'consent_version')) {
                Schema::table('contact_inquiries', function (Blueprint $table) {
                    $table->string('consent_version')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        // noop
    }
};
