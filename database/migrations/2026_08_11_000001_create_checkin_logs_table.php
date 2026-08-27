<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reconstructed from code/tests; keep minimal and local-only note
        if (!Schema::hasTable('checkin_logs')) {
            Schema::create('checkin_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('attendee_id')->nullable()->index();
                $table->unsignedBigInteger('event_id')->nullable()->index();
                $table->unsignedBigInteger('scanned_by_user_id')->nullable()->index();
                $table->string('scan_result')->nullable();
                $table->timestamp('scanned_at')->nullable();
                $table->text('notes')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Baseline reconciliation safety: do NOT drop runtime tables during rollback.
        // This migration reconstructs `checkin_logs` from code/tests; an environment may
        // already have this table. To avoid accidental production data loss, the
        // down() is intentionally a NO-OP. Production DDL verification is still required.
        return;
    }
};
