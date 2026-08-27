<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ticket_types.per_order_limit
        if (Schema::hasTable('ticket_types') && !Schema::hasColumn('ticket_types', 'per_order_limit')) {
            Schema::table('ticket_types', function (Blueprint $table) {
                $table->integer('per_order_limit')->nullable()->default(10)->after('quota');
            });
        }

        // certificate_templates.template_type
        if (Schema::hasTable('certificate_templates') && !Schema::hasColumn('certificate_templates', 'template_type')) {
            Schema::table('certificate_templates', function (Blueprint $table) {
                $table->string('template_type')->nullable()->after('background_image_url');
            });
        }
        // certificate_templates.template_url and field_positions_json (runtime names used by code)
        if (Schema::hasTable('certificate_templates')) {
            if (!Schema::hasColumn('certificate_templates', 'template_url')) {
                Schema::table('certificate_templates', function (Blueprint $table) {
                    $table->string('template_url')->nullable()->after('template_type');
                });
            }
            if (!Schema::hasColumn('certificate_templates', 'field_positions_json')) {
                Schema::table('certificate_templates', function (Blueprint $table) {
                    $table->text('field_positions_json')->nullable()->after('template_url');
                });
            }
        }

        // contact_inquiries.phone_country_code and resolved_at
        if (Schema::hasTable('contact_inquiries')) {
            if (!Schema::hasColumn('contact_inquiries', 'phone_country_code')) {
                Schema::table('contact_inquiries', function (Blueprint $table) {
                    $table->string('phone_country_code', 12)->nullable()->after('email');
                });
            }
            if (!Schema::hasColumn('contact_inquiries', 'resolved_at')) {
                Schema::table('contact_inquiries', function (Blueprint $table) {
                    $table->timestamp('resolved_at')->nullable()->after('updated_at');
                });
            }
        }

        // registrations.selected_price_period_id and capacity_reservation_status
        if (Schema::hasTable('registrations')) {
            if (!Schema::hasColumn('registrations', 'selected_price_period_id')) {
                Schema::table('registrations', function (Blueprint $table) {
                    $table->unsignedBigInteger('selected_price_period_id')->nullable()->after('selected_price');
                });
            }
            if (!Schema::hasColumn('registrations', 'capacity_reservation_status')) {
                Schema::table('registrations', function (Blueprint $table) {
                    $table->string('capacity_reservation_status')->nullable()->default('active')->after('payment_status');
                });
            }
        }

        // kiosk_search_logs additional columns
        if (Schema::hasTable('kiosk_search_logs')) {
            if (!Schema::hasColumn('kiosk_search_logs', 'search_type')) {
                Schema::table('kiosk_search_logs', function (Blueprint $table) {
                    $table->string('search_type')->nullable()->after('event_id');
                });
            }
            if (!Schema::hasColumn('kiosk_search_logs', 'search_value')) {
                Schema::table('kiosk_search_logs', function (Blueprint $table) {
                    $table->string('search_value')->nullable()->after('search_type');
                });
            }
            if (!Schema::hasColumn('kiosk_search_logs', 'result_status')) {
                Schema::table('kiosk_search_logs', function (Blueprint $table) {
                    $table->string('result_status')->nullable()->after('search_value');
                });
            }
            if (!Schema::hasColumn('kiosk_search_logs', 'matched_registration_id')) {
                Schema::table('kiosk_search_logs', function (Blueprint $table) {
                    $table->unsignedBigInteger('matched_registration_id')->nullable()->after('result_status')->index();
                });
            }
        }
        // event_cards.template_key (used by CertificateController.eventCard)
        if (Schema::hasTable('event_cards') && !Schema::hasColumn('event_cards', 'template_key')) {
            Schema::table('event_cards', function (Blueprint $table) {
                $table->string('template_key')->nullable()->after('card_number');
            });
        }
    }

    public function down(): void
    {
        // Intentionally a NO-OP for safety in production.
        return;
    }
};
