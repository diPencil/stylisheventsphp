<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        // table: admin_notifications
        if (!Schema::hasTable('admin_notifications')) {
            Schema::create('admin_notifications', function (Blueprint $table) {
                $table->id();
                $table->string('type')->nullable();
                $table->string('title')->nullable();
                $table->string('title_en')->nullable();
                $table->string('title_ar')->nullable();
                $table->text('message')->nullable();
                $table->text('message_en')->nullable();
                $table->text('message_ar')->nullable();
                $table->string('entity_type')->nullable();
                $table->unsignedBigInteger('entity_id')->nullable()->index();
                $table->boolean('is_read')->default(0);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        // table: attendees
        if (!Schema::hasTable('attendees')) {
            Schema::create('attendees', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('event_id')->nullable()->index();
                $table->unsignedBigInteger('registration_id')->nullable()->index();
                $table->unsignedBigInteger('ticket_type_id')->nullable()->index();
                $table->string('full_name')->nullable();
                $table->string('email')->nullable();
                $table->string('mobile')->nullable();
                $table->string('qr_status')->nullable()->default('active');
                $table->timestamp('checked_in_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('name')->nullable();
            });
        }

        // table: audit_logs
        if (!Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('action')->nullable();
                $table->string('entity_type')->nullable();
                $table->unsignedBigInteger('entity_id')->nullable()->index();
                $table->string('metadata')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->string('metadata_json')->nullable();
                $table->string('ip_address')->nullable();
                $table->string('user_agent')->nullable();
            });
        }

        // table: bank_accounts
        if (!Schema::hasTable('bank_accounts')) {
            Schema::create('bank_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('bank_name')->nullable();
                $table->string('account_name')->nullable();
                $table->string('account_number')->nullable();
                $table->string('iban')->nullable();
                $table->string('currency')->nullable();
                $table->boolean('is_active')->default(1);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        // table: cache
        if (!Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->id();
                $table->string('value')->nullable();
                $table->integer('expiration')->nullable();
                $table->unique(['key']);
            });
        }

        // table: cache_locks
        if (!Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->id();
                $table->string('owner');
                $table->integer('expiration');
                $table->index(['expiration']);
                $table->unique(['key']);
            });
        }

        // table: certificate_templates
        if (!Schema::hasTable('certificate_templates')) {
            Schema::create('certificate_templates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('event_id')->nullable()->index();
                $table->string('name')->nullable();
                $table->string('name_en')->nullable();
                $table->string('name_ar')->nullable();
                $table->text('html_template')->nullable();
                $table->string('background_image_url')->nullable();
                $table->boolean('is_default')->default(0);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->text('config_json')->nullable();
            });
        }

        // table: certificates
        if (!Schema::hasTable('certificates')) {
            Schema::create('certificates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('attendee_id')->nullable()->index();
                $table->unsignedBigInteger('event_id')->nullable()->index();
                $table->string('certificate_number')->nullable();
                $table->string('status')->nullable();
                $table->string('file_url')->nullable();
                $table->string('pdf_url')->nullable();
                $table->timestamp('issued_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        // table: contact_inquiries
        if (!Schema::hasTable('contact_inquiries')) {
            Schema::create('contact_inquiries', function (Blueprint $table) {
                $table->id();
                $table->string('reference_code')->nullable();
                $table->string('full_name')->nullable();
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('inquiry_type')->nullable();
                $table->string('subject')->nullable();
                $table->text('message')->nullable();
                $table->string('status')->nullable();
                $table->string('source_page')->nullable();
                $table->timestamp('consent_accepted_at')->nullable();
                $table->text('admin_notes')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        // table: contact_inquiries_reference_counter
        if (!Schema::hasTable('contact_inquiries_reference_counter')) {
            Schema::create('contact_inquiries_reference_counter', function (Blueprint $table) {
                $table->id();
                $table->string('counter_date')->nullable();
                $table->boolean('last_number')->default(0);
                $table->unique(['counter_date']);
            });
        }

        // table: doctors
        if (!Schema::hasTable('doctors')) {
            Schema::create('doctors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('full_name')->nullable();
                $table->string('email')->nullable();
                $table->string('mobile')->nullable();
                $table->string('address')->nullable();
                $table->string('country_code')->nullable();
                $table->string('country_name')->nullable();
                $table->string('city')->nullable();
                $table->string('specialty')->default('N/A');
                $table->string('nationality')->nullable();
                $table->string('preferred_language')->nullable();
                $table->string('status')->nullable()->default('active');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        // table: event_cards
        if (!Schema::hasTable('event_cards')) {
            Schema::create('event_cards', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('event_id')->nullable()->index();
                $table->string('name')->nullable();
                $table->string('image_url')->nullable();
                $table->string('template_json')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->text('config_json')->nullable();
            });
        }

        // table: event_staff_assignments
        if (!Schema::hasTable('event_staff_assignments')) {
            Schema::create('event_staff_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('event_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->boolean('is_active')->default(1);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        // table: events
        if (!Schema::hasTable('events')) {
            Schema::create('events', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->nullable();
                $table->string('title_en')->nullable();
                $table->string('title_ar')->nullable();
                $table->string('summary_en')->nullable();
                $table->string('summary_ar')->nullable();
                $table->text('description_en')->nullable();
                $table->string('type')->nullable();
                $table->string('status')->nullable();
                $table->unsignedBigInteger('venue_id')->nullable()->index();
                $table->unsignedBigInteger('organizer_id')->nullable()->index();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->string('timezone')->nullable();
                $table->string('cover_image_url')->nullable();
                $table->string('banner_image_url')->nullable();
                // Historical compatibility field: some deployed schemas included
                // `event_details_image_url` prior to 2026-08-12. This is added
                // here to preserve the original migration contract so that the
                // deployed Aug-12 migration can use ->after('event_details_image_url')
                // without failing. The exact production DDL must be verified
                // before any production migration; see release notes.
                $table->string('event_details_image_url', 500)->nullable();
                $table->text('gallery_json')->nullable();
                $table->string('google_maps_url')->nullable();
                $table->timestamp('registration_starts_at')->nullable();
                $table->timestamp('registration_ends_at')->nullable();
                $table->boolean('public_registration_enabled')->default(1);
                $table->string('registration_approval_mode')->nullable()->default('automatic');
                $table->string('registration_access')->nullable()->default('guest_allowed');
                $table->integer('max_tickets_per_checkout')->nullable()->default(4);
                $table->integer('max_attendees')->nullable()->default(100);
                $table->integer('capacity_hold_hours_override')->nullable();
                $table->boolean('manual_payment_enabled')->default(0);
                $table->boolean('is_active')->default(1);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->text('description_ar')->nullable();
                $table->integer('capacity')->nullable();
                $table->string('location_en')->nullable();
                $table->string('location_ar')->nullable();
                $table->unique(['slug']);
            });
        }

        // table: generated_tickets
        if (!Schema::hasTable('generated_tickets')) {
            Schema::create('generated_tickets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('registration_id')->nullable()->index();
                $table->unsignedBigInteger('attendee_id')->nullable()->index();
                $table->string('ticket_number')->nullable();
                $table->string('qr_token')->nullable();
                $table->string('pdf_url')->nullable();
                $table->timestamp('generated_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        // table: job_batches
        if (!Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->string('failed_job_ids');
                $table->string('options')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('created_at');
                $table->timestamp('finished_at')->nullable();
                $table->unique(['id']);
            });
        }

        // table: jobs
        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->nullable();
                $table->text('payload')->nullable();
                $table->integer('attempts')->nullable();
                $table->timestamp('reserved_at')->nullable();
                $table->timestamp('available_at')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        // table: kiosk_search_logs
        if (!Schema::hasTable('kiosk_search_logs')) {
            Schema::create('kiosk_search_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('event_id')->nullable()->index();
                $table->string('query')->nullable();
                $table->integer('result_count')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        // table: orders
        if (!Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->id();
                $table->string('order_number')->nullable();
                $table->string('status')->nullable();
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->string('currency')->nullable();
                $table->string('payment_method')->nullable();
                $table->string('payment_reference')->nullable();
                $table->string('payment_proof_url')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->string('customer_email')->nullable();
                $table->string('customer_name')->nullable();
                $table->decimal('amount', 12, 2)->default(0);
            });
        }

        // table: password_reset_tokens
        if (!Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('token')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->unique(['email']);
            });
        }

        // table: project_settings
        if (!Schema::hasTable('project_settings')) {
            Schema::create('project_settings', function (Blueprint $table) {
                $table->id();
                $table->string('setting_key')->nullable();
                $table->string('setting_value')->nullable();
                $table->string('value')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['setting_key']);
            });
        }

        // table: public_checkout_sessions
        if (!Schema::hasTable('public_checkout_sessions')) {
            Schema::create('public_checkout_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('idempotency_key')->nullable();
                $table->unsignedBigInteger('event_id')->nullable()->index();
                $table->unsignedBigInteger('registration_id')->nullable()->index();
                $table->text('response_json')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['idempotency_key']);
            });
        }

        // table: registrations
        if (!Schema::hasTable('registrations')) {
            Schema::create('registrations', function (Blueprint $table) {
                $table->id();
                $table->string('registration_number')->nullable();
                $table->unsignedBigInteger('event_id')->nullable()->index();
                $table->unsignedBigInteger('doctor_id')->nullable()->index();
                $table->unsignedBigInteger('ticket_type_id')->nullable()->index();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->string('registration_status')->nullable();
                $table->string('payment_status')->nullable();
                $table->string('selected_currency')->nullable();
                $table->decimal('selected_price', 12, 2)->default(0);
                $table->string('payment_reference')->nullable();
                $table->string('payment_proof_url')->nullable();
                $table->string('idempotency_key')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->integer('ticket_quantity')->nullable();
                $table->string('customer_name')->nullable();
                $table->string('customer_email')->nullable();
            });
        }

        // table: reviews
        if (!Schema::hasTable('reviews')) {
            Schema::create('reviews', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('event_id')->nullable()->index();
                $table->unsignedBigInteger('attendee_id')->nullable()->index();
                $table->unsignedBigInteger('customer_id')->nullable()->index();
                $table->integer('rating')->nullable();
                $table->string('title')->nullable();
                $table->string('comment')->nullable();
                $table->string('status')->nullable()->default('pending');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        // table: role_permissions
        if (!Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('role_id')->nullable()->index();
                $table->string('permission_key')->nullable();
                $table->boolean('allowed')->default(0);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['role_id', 'permission_key']);
            });
        }

        // table: roles
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('code')->nullable();
                $table->string('name')->nullable();
                $table->string('name_en')->nullable();
                $table->string('name_ar')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_system')->default(0);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['code']);
            });
        }

        // Reconcile users.role_id foreign key to roles.id safely and idempotently for MySQL.
        // Do not modify existing data; only add index/constraint when missing.
        if (Schema::hasTable('users') && Schema::hasTable('roles')) {
            try {
                $dbName = DB::getDatabaseName();
                $fkExists = DB::selectOne(
                    'SELECT COUNT(1) as c FROM information_schema.key_column_usage WHERE table_schema = ? AND table_name = ? AND constraint_name = ? AND referenced_table_name IS NOT NULL',
                    [$dbName, 'users', 'fk_users_role']
                );
                if (empty($fkExists->c)) {
                    // ensure a named index exists before adding a foreign key with the same name
                    $idxExists = DB::selectOne(
                        'SELECT COUNT(1) as c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
                        [$dbName, 'users', 'fk_users_role']
                    );
                    if (empty($idxExists->c)) {
                        Schema::table('users', function (Blueprint $table) {
                            $table->index('role_id', 'fk_users_role');
                        });
                    }

                    // add foreign key constraint if still missing
                    $fkExists2 = DB::selectOne(
                        'SELECT COUNT(1) as c FROM information_schema.key_column_usage WHERE table_schema = ? AND table_name = ? AND constraint_name = ? AND referenced_table_name IS NOT NULL',
                        [$dbName, 'users', 'fk_users_role']
                    );
                    if (empty($fkExists2->c)) {
                        Schema::table('users', function (Blueprint $table) {
                            $table->foreign('role_id', 'fk_users_role')->references('id')->on('roles');
                        });
                    }
                }
            } catch (\Throwable $e) {
                // If information_schema is not available (non-MySQL) or any check fails, skip reconciliation.
            }
        }

        // table: sessions
        if (!Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('ip_address')->nullable();
                $table->string('user_agent')->nullable();
                $table->text('payload')->nullable();
                $table->integer('last_activity')->nullable();
                $table->unique(['id']);
            });
        }

        // table: ticket_price_periods
        if (!Schema::hasTable('ticket_price_periods')) {
            Schema::create('ticket_price_periods', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ticket_type_id')->nullable()->index();
                $table->string('label_en')->nullable();
                $table->string('label_ar')->nullable();
                $table->decimal('price', 12, 2)->default(0);
                $table->decimal('price_egp', 12, 2)->default(0);
                $table->decimal('price_usd', 12, 2)->default(0);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->boolean('is_active')->default(1);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        // table: ticket_types
        if (!Schema::hasTable('ticket_types')) {
            Schema::create('ticket_types', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('event_id')->nullable()->index();
                $table->string('name_en')->nullable();
                $table->string('name_ar')->nullable();
                $table->text('description_en')->nullable();
                $table->text('description_ar')->nullable();
                $table->boolean('is_active')->default(1);
                $table->integer('quota')->nullable();
                $table->decimal('price', 12, 2)->default(0);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
            });
        }

        // table: users
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('role_id')->nullable()->index();
                $table->string('name')->nullable();
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('country_code')->nullable();
                $table->string('country_name')->nullable();
                $table->string('password')->nullable();
                $table->string('password_hash')->nullable();
                $table->string('status')->nullable()->default('active');
                $table->string('preferred_language')->nullable()->default('en');
                $table->timestamp('email_verified_at')->nullable();
                $table->string('remember_token')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('last_login_at')->nullable();
                $table->string('gender')->nullable();
                $table->string('username')->nullable();
                $table->string('avatar_url')->nullable();
                $table->string('notes')->nullable();
                $table->unique(['email']);
            });
        }

        // table: venues
        if (!Schema::hasTable('venues')) {
            Schema::create('venues', function (Blueprint $table) {
                $table->id();
                $table->string('name_en')->nullable();
                $table->string('name_ar')->nullable();
                $table->string('city_en')->nullable();
                $table->string('city_ar')->nullable();
                $table->string('address_en')->nullable();
                $table->string('address_ar')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Baseline reconciliation safety: This migration reconciles many historical tables
        // from the pre-source-control database. Rolling back and dropping these tables
        // could delete production runtime data if the tables existed before Laravel
        // recorded the migration. To avoid accidental data loss, the down() is
        // intentionally a NO-OP. For disposable development databases, drop or
        // recreate the database manually if a full reset is required.
        return;
    }
};
