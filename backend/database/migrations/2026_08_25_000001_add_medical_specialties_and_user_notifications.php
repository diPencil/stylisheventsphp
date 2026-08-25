<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('specialties')) {
            Schema::create('specialties', function (Blueprint $table) {
                $table->id();
                $table->string('name_en', 180);
                $table->string('name_ar', 180);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique('name_en');
                $table->index('is_active');
            });
        }

        if (Schema::hasTable('doctors') && !Schema::hasColumn('doctors', 'specialty_id')) {
            Schema::table('doctors', function (Blueprint $table) {
                $table->foreignId('specialty_id')->nullable()->after('specialty')->constrained('specialties')->nullOnDelete();
                $table->index('specialty_id');
            });
        }

        if (Schema::hasTable('events') && !Schema::hasColumn('events', 'target_all_specialties')) {
            Schema::table('events', function (Blueprint $table) {
                $table->boolean('target_all_specialties')->default(false)->after('max_attendees');
                $table->index(['status', 'target_all_specialties']);
            });
        }

        if (!Schema::hasTable('event_specialty')) {
            Schema::create('event_specialty', function (Blueprint $table) {
                $table->id();
                $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
                $table->foreignId('specialty_id')->constrained('specialties')->restrictOnDelete();
                $table->timestamps();
                $table->unique(['event_id', 'specialty_id']);
                $table->index(['specialty_id', 'event_id']);
            });
        }

        if (!Schema::hasTable('user_notifications')) {
            Schema::create('user_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('type', 80);
                $table->string('title_en', 180);
                $table->string('title_ar', 180)->nullable();
                $table->text('message_en')->nullable();
                $table->text('message_ar')->nullable();
                $table->string('entity_type', 80)->nullable();
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->string('action_url', 500)->nullable();
                $table->timestamp('read_at')->nullable();
                $table->string('dedupe_key', 190)->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'dedupe_key']);
                $table->index(['user_id', 'read_at', 'created_at']);
                $table->index(['entity_type', 'entity_id']);
            });
        }

        if (Schema::hasTable('specialties') && DB::table('specialties')->count() === 0) {
            DB::table('specialties')->insert([
                ['name_en' => 'Cardiology', 'name_ar' => 'أمراض القلب', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['name_en' => 'Dermatology', 'name_ar' => 'الأمراض الجلدية', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['name_en' => 'Dentistry', 'name_ar' => 'طب الأسنان', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['name_en' => 'Orthopedics', 'name_ar' => 'جراحة العظام', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['name_en' => 'Pediatrics', 'name_ar' => 'طب الأطفال', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['name_en' => 'Internal Medicine', 'name_ar' => 'الباطنة', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['name_en' => 'Ophthalmology', 'name_ar' => 'طب العيون', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('event_specialty');
        if (Schema::hasTable('events') && Schema::hasColumn('events', 'target_all_specialties')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropColumn('target_all_specialties');
            });
        }
        if (Schema::hasTable('doctors') && Schema::hasColumn('doctors', 'specialty_id')) {
            Schema::table('doctors', function (Blueprint $table) {
                $table->dropConstrainedForeignId('specialty_id');
            });
        }
        Schema::dropIfExists('specialties');
    }
};
