<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ticket_price_periods') && !Schema::hasColumn('ticket_price_periods', 'currency')) {
            Schema::table('ticket_price_periods', function (Blueprint $table) {
                $table->string('currency', 8)->default('USD')->after('price_usd');
            });
        }
    }

    public function down(): void
    {
        return;
    }
};
