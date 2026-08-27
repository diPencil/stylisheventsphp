<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bank_accounts') && !Schema::hasColumn('bank_accounts', 'swift_code')) {
            Schema::table('bank_accounts', function (Blueprint $table) {
                $table->string('swift_code')->nullable()->after('iban');
            });
        }
    }

    public function down(): void
    {
        // No-op for local additive reconciliation safety.
    }
};
