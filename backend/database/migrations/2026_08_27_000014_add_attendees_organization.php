<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendees') && !Schema::hasColumn('attendees', 'organization')) {
            Schema::table('attendees', function (Blueprint $table) {
                $table->string('organization')->nullable();
            });
        }
    }

    public function down(): void
    {
        // noop
    }
};
