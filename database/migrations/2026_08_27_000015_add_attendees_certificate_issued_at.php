<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendees') && !Schema::hasColumn('attendees', 'certificate_issued_at')) {
            Schema::table('attendees', function (Blueprint $table) {
                $table->timestamp('certificate_issued_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // noop
    }
};
