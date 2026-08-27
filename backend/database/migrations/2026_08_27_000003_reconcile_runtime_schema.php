<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // public_checkout_sessions: add runtime columns expected by controllers
        if (Schema::hasTable('public_checkout_sessions')) {
            if (!Schema::hasColumn('public_checkout_sessions', 'session_key')) {
                Schema::table('public_checkout_sessions', function (Blueprint $table) {
                    $table->string('session_key')->nullable()->after('id');
                    $table->index('session_key');
                });
            }
            if (!Schema::hasColumn('public_checkout_sessions', 'payload_hash')) {
                Schema::table('public_checkout_sessions', function (Blueprint $table) {
                    $table->string('payload_hash')->nullable()->after('response_json');
                });
            }
            if (!Schema::hasColumn('public_checkout_sessions', 'confirmation_token_hash')) {
                Schema::table('public_checkout_sessions', function (Blueprint $table) {
                    $table->string('confirmation_token_hash')->nullable()->after('payload_hash');
                    $table->timestamp('confirmation_token_expires_at')->nullable()->after('confirmation_token_hash');
                });
            }
            if (!Schema::hasColumn('public_checkout_sessions', 'status')) {
                Schema::table('public_checkout_sessions', function (Blueprint $table) {
                    $table->string('status')->nullable()->after('response_json');
                });
            }
        }

        // orders: add event_id, customer_phone, grand_total if missing
        if (Schema::hasTable('orders')) {
            if (!Schema::hasColumn('orders', 'event_id')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->unsignedBigInteger('event_id')->nullable()->after('order_number')->index();
                });
            }
            if (!Schema::hasColumn('orders', 'customer_phone')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->string('customer_phone')->nullable()->after('customer_email');
                });
            }
            if (!Schema::hasColumn('orders', 'grand_total')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->decimal('grand_total', 12, 2)->default(0)->after('subtotal');
                });
            }
        }

        // venues: add capacity
        if (Schema::hasTable('venues') && !Schema::hasColumn('venues', 'capacity')) {
            Schema::table('venues', function (Blueprint $table) {
                $table->integer('capacity')->nullable()->default(0)->after('address_ar');
            });
        }
    }

    public function down(): void
    {
        // Intentionally NO-OP: do not remove potentially production data columns
        return;
    }
};
