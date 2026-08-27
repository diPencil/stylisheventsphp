<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('registrations')) return;

        if (!Schema::hasColumn('registrations', 'reservation_expires_at')) {
            Schema::table('registrations', function (Blueprint $table) {
                $table->timestamp('reservation_expires_at')->nullable();
            });
        }

        if (!Schema::hasColumn('registrations', 'capacity_released_at')) {
            Schema::table('registrations', function (Blueprint $table) {
                $table->timestamp('capacity_released_at')->nullable();
            });
        }

        if (!Schema::hasColumn('registrations', 'capacity_release_reason')) {
            Schema::table('registrations', function (Blueprint $table) {
                $table->string('capacity_release_reason')->nullable();
            });
        }

        if (!Schema::hasColumn('registrations', 'payment_reviewed_by_user_id')) {
            Schema::table('registrations', function (Blueprint $table) {
                $table->unsignedBigInteger('payment_reviewed_by_user_id')->nullable();
            });
        }

        if (!Schema::hasColumn('registrations', 'payment_reviewed_at')) {
            Schema::table('registrations', function (Blueprint $table) {
                $table->timestamp('payment_reviewed_at')->nullable();
            });
        }

        if (!Schema::hasColumn('registrations', 'payment_rejection_reason')) {
            Schema::table('registrations', function (Blueprint $table) {
                $table->string('payment_rejection_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        // noop
    }
};
