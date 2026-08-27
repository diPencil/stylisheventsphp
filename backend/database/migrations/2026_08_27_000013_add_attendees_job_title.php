<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendees') && !Schema::hasColumn('attendees', 'job_title')) {
            Schema::table('attendees', function (Blueprint $table) {
                $table->string('job_title')->nullable();
            });
        }
    }

    public function down(): void
    {
        // noop
    }
};
