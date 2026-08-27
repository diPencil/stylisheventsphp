<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('public_checkout_sessions')) return;

        // ticket_type_id: runtime code inserts ticket_type_id for checkout context
        if (!Schema::hasColumn('public_checkout_sessions', 'ticket_type_id')) {
            Schema::table('public_checkout_sessions', function (Blueprint $table) {
                $table->unsignedBigInteger('ticket_type_id')->nullable()->after('event_id');
                $table->index('ticket_type_id');
            });
        }

        // customer_email: snapshot of customer contact inserted at checkout
        if (!Schema::hasColumn('public_checkout_sessions', 'customer_email')) {
            Schema::table('public_checkout_sessions', function (Blueprint $table) {
                $table->string('customer_email')->nullable()->after('ticket_type_id');
            });
        }

        // expires_at: controls session expiry (used by runtime to set short TTL)
        if (!Schema::hasColumn('public_checkout_sessions', 'expires_at')) {
            Schema::table('public_checkout_sessions', function (Blueprint $table) {
                $table->timestamp('expires_at')->nullable()->after('status');
            });
        }

        // confirmed_at: updated when registration is confirmed (getRegistration)
        if (!Schema::hasColumn('public_checkout_sessions', 'confirmed_at')) {
            Schema::table('public_checkout_sessions', function (Blueprint $table) {
                $table->timestamp('confirmed_at')->nullable()->after('confirmation_token_expires_at');
            });
        }
    }

    public function down(): void
    {
        // NO-OP to avoid accidental destructive rollbacks in production.
        return;
    }
};
