<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('event_brief_requests')) {
            Schema::create('event_brief_requests', function (Blueprint $table) {
                $table->id();
                $table->string('reference_number', 80)->unique();
                $table->enum('request_type', ['single','recurring','annual','general'])->default('single');
                $table->string('full_name', 180);
                $table->string('email', 180);
                $table->string('country_code', 12);
                $table->string('phone', 40);
                $table->enum('preferred_contact_method', ['phone','email','whatsapp'])->default('email');
                $table->string('job_title', 180);
                $table->string('organization', 180)->nullable();
                $table->string('event_name', 220)->nullable();
                $table->string('event_type', 80)->nullable();
                $table->string('event_date', 80)->nullable();
                $table->boolean('is_date_flexible')->default(false);
                $table->string('country', 120)->nullable();
                $table->string('location', 220)->nullable();
                $table->enum('venue_status', ['known','not_decided'])->default('not_decided');
                $table->string('expected_attendance', 80)->nullable();
                $table->string('budget_range', 120)->nullable();
                $table->longText('services_json')->nullable();
                $table->text('objectives')->nullable();
                $table->text('event_brief');
                $table->text('additional_requirements')->nullable();
                $table->boolean('privacy_consent')->default(true);
                $table->boolean('communication_consent')->default(false);
                $table->enum('language', ['ar','en'])->default('en');
                $table->enum('status', ['new','reviewing','contacted','closed'])->default('new');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // This migration reconciles a pre-existing production table into source control.
        // Rolling back this migration must NOT drop the production table or any runtime data.
        // To protect production, the down() is intentionally a NO-OP.
        // If you need to remove this table from a fresh test environment, do so manually.
        return;
    }
};
