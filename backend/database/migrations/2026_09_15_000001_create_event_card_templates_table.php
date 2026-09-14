<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_card_templates')) {
            return;
        }

        Schema::create('event_card_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id')->index();
            $table->string('name');
            $table->text('background_url')->nullable();
            // Editable texts + visibility flags + venue logo. Current hardcoded
            // card rendering is the implicit default when keys are missing.
            $table->text('fields_json')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_card_templates');
    }
};
