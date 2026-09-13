<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('email_verification_codes')) {
            Schema::create('email_verification_codes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('code_hash');
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('consumed_at')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        // Grandfather existing accounts: they were created before verification existed.
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'email_verified_at')) {
            DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
        }
    }

    public function down(): void
    {
        return;
    }
};
