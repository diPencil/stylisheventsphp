<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('role_id')->nullable()->index();
                $table->string('name')->nullable();
                $table->string('email')->unique();
                $table->string('phone')->nullable();
                $table->string('country_code')->nullable();
                $table->string('country_name')->nullable();
                // Keep legacy `password` for compatibility; primary auth uses `password_hash`
                $table->string('password')->nullable();
                $table->string('password_hash')->nullable();
                $table->string('status')->default('active');
                $table->string('preferred_language')->default('en');
                $table->timestamp('email_verified_at')->nullable();
                $table->string('remember_token')->nullable();
                $table->timestamps();
                $table->timestamp('last_login_at')->nullable();
                $table->string('gender')->nullable();
                $table->string('username')->nullable();
                $table->text('avatar_url')->nullable();
                $table->text('notes')->nullable();
            });
        }

        if (!Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Baseline reconciliation safety: do NOT drop historical runtime tables during rollback.
        // These migrations reconcile pre-existing tables into source control; an environment
        // may have had these tables before Laravel recorded the migration. To avoid accidental
        // data loss in production, the down() is intentionally a NO-OP. For disposable
        // development environments, drop or recreate the database manually if needed.
        return;
    }
};
