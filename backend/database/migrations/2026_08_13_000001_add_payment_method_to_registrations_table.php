<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('registrations', 'payment_method')) {
            Schema::table('registrations', function (Blueprint $table) {
                $table->string('payment_method', 100)->nullable()->after('payment_reference');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('registrations', 'payment_method')) {
            Schema::table('registrations', function (Blueprint $table) {
                $table->dropColumn('payment_method');
            });
        }
    }
};
