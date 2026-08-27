<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LocalDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('LocalDemoSeeder can only be run in local or testing environments.');
        }

        $this->call(RolesSeeder::class);

        $now = now()->toDateTimeString();

        // Roles (already seeded by RolesSeeder) - ensure minimal display names

        // QA Admin
        $adminEmail = 'qa-admin-local@local.test';
        $adminRoleId = DB::table('roles')->where('code', 'admin')->value('id') ?? 1;
        $admin = DB::table('users')->where('email', $adminEmail)->first();
        if ($admin) {
            DB::table('users')->where('id', $admin->id)->update(['name' => 'QA Admin', 'role_id' => $adminRoleId, 'status' => 'active', 'preferred_language' => 'en', 'updated_at' => $now]);
            $adminId = $admin->id;
        } else {
            $adminId = DB::table('users')->insertGetId(['role_id' => $adminRoleId, 'name' => 'QA Admin', 'email' => $adminEmail, 'username' => 'qaadmin', 'password_hash' => Hash::make('Password123!'), 'status' => 'active', 'preferred_language' => 'en', 'created_at' => $now, 'updated_at' => $now]);
        }

        // Organizer
        $orgEmail = 'qa-organizer@local.test';
        $organizer = DB::table('users')->where('email', $orgEmail)->first();
        if ($organizer) {
            $organizerId = $organizer->id;
        } else {
            $organizerId = DB::table('users')->insertGetId(['role_id' => DB::table('roles')->where('code', 'organizer')->value('id') ?? 2, 'name' => 'QA Organizer', 'email' => $orgEmail, 'username' => 'qaorganizer', 'password_hash' => Hash::make('Password123!'), 'status' => 'active', 'preferred_language' => 'en', 'created_at' => $now, 'updated_at' => $now]);
        }

        // Venue
        $venueId = null;
        $hasSlug = DB::select("SHOW COLUMNS FROM venues LIKE 'slug'");
        if ($hasSlug) {
            $v = DB::table('venues')->where('slug', 'qa-hall-1')->first();
            if ($v) $venueId = $v->id;
            else $venueId = DB::table('venues')->insertGetId(['slug' => 'qa-hall-1', 'name' => 'QA Hall 1', 'created_at' => $now, 'updated_at' => $now]);
        } else {
            $v = DB::table('venues')->where('name_en', 'QA Hall 1')->orWhere('name_ar', 'QA Hall 1')->first();
            if ($v) $venueId = $v->id;
            else $venueId = DB::table('venues')->insertGetId(['name_en' => 'QA Hall 1', 'created_at' => $now, 'updated_at' => $now]);
        }

        // Helper: detect column existence
        $colExists = function (string $table, string $col): bool {
            $res = DB::select("SHOW COLUMNS FROM $table LIKE '$col'");
            return !empty($res);
        };

        // Specialties (handle schema variations where `code` may not exist)
        $specialties = [
            ['code' => 'cardiology', 'name_en' => 'Cardiology', 'name_ar' => 'القلب'],
            ['code' => 'dermatology', 'name_en' => 'Dermatology', 'name_ar' => 'الأمراض الجلدية'],
            ['code' => 'pediatrics', 'name_en' => 'Pediatrics', 'name_ar' => 'طب الأطفال'],
        ];
        $specHasCode = $colExists('specialties', 'code');
        foreach ($specialties as $s) {
            if ($specHasCode) {
                DB::table('specialties')->updateOrInsert(['code' => $s['code']], ['name_en' => $s['name_en'], 'name_ar' => $s['name_ar'], 'is_active' => 1, 'updated_at' => $now, 'created_at' => $now]);
            } else {
                DB::table('specialties')->updateOrInsert(['name_en' => $s['name_en']], ['name_ar' => $s['name_ar'], 'is_active' => 1, 'updated_at' => $now, 'created_at' => $now]);
            }
        }

        // Build specialty map keyed by code when available, else by name_en
        if ($specHasCode) {
            $specMap = DB::table('specialties')->pluck('id', 'code')->toArray();
            $specCodes = array_keys($specMap ?: []);
        } else {
            $specMapByName = DB::table('specialties')->pluck('id', 'name_en')->toArray();
            $specCodes = array_keys($specMapByName ?: []);
            $specMap = $specMapByName; // map name_en -> id
        }

        // Doctors
        for ($i = 1; $i <= 8; $i++) {
            $email = "qa-doctor-$i@local.test";
            $user = DB::table('users')->where('email', $email)->first();
            if (!$user) {
                $uid = DB::table('users')->insertGetId(['role_id' => DB::table('roles')->where('code', 'doctor')->value('id') ?? 5, 'name' => "Dr. QA $i", 'email' => $email, 'username' => 'qadr' . $i, 'password_hash' => Hash::make('Password123!'), 'status' => 'active', 'preferred_language' => 'en', 'created_at' => $now, 'updated_at' => $now]);
                if (!empty($specCodes)) {
                    $specCode = $specCodes[$i % count($specCodes)];
                    DB::table('doctors')->insert(['user_id' => $uid, 'full_name' => "Dr. QA $i", 'email' => $email, 'mobile' => '+201000000' . str_pad($i, 2, '0', STR_PAD_LEFT), 'specialty' => $specCode, 'specialty_id' => $specMap[$specCode] ?? null, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }

        // Events
        $events = [
            ['qa-upcoming', 'QA Upcoming Event', now()->addDays(10)->toDateTimeString()],
            ['qa-current', 'QA Current Event', now()->subHour()->toDateTimeString()],
            ['qa-past', 'QA Past Event', now()->subDays(30)->toDateTimeString()],
            ['qa-draft', 'QA Draft Event', now()->addDays(60)->toDateTimeString()],
            ['qa-public', 'QA Public Registration', now()->addDays(5)->toDateTimeString()],
            ['qa-closed', 'QA Registration Closed', now()->addDays(2)->toDateTimeString()],
        ];
        foreach ($events as $e) {
            [$slug, $title, $starts_at] = $e;
            $existing = DB::table('events')->where('slug', $slug)->first();
            if ($existing) {
                DB::table('events')->where('id', $existing->id)->update(['title_en' => $title, 'title_ar' => $title, 'summary_en' => $title, 'status' => 'active', 'venue_id' => $venueId, 'organizer_id' => $organizerId, 'starts_at' => $starts_at, 'updated_at' => $now]);
            } else {
                DB::table('events')->insert(['slug' => $slug, 'title_en' => $title, 'title_ar' => $title, 'summary_en' => $title, 'status' => 'active', 'venue_id' => $venueId, 'organizer_id' => $organizerId, 'starts_at' => $starts_at, 'created_at' => $now, 'updated_at' => $now, 'event_details_image_url' => null]);
            }
        }

        // Ticket types & price periods for qa-upcoming
        $firstEventId = DB::table('events')->where('slug', 'qa-upcoming')->value('id');
        if ($firstEventId) {
            $ticketHasName = $colExists('ticket_types', 'name');
            if ($ticketHasName) {
                $tt = DB::table('ticket_types')->where('event_id', $firstEventId)->where('name', 'General')->first();
                if ($tt) $ttId = $tt->id; else $ttId = DB::table('ticket_types')->insertGetId(['event_id' => $firstEventId, 'name' => 'General', 'price' => 100, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                // fallback to name_en
                $tt = DB::table('ticket_types')->where('event_id', $firstEventId)->where('name_en', 'General')->first();
                if ($tt) $ttId = $tt->id; else $ttId = DB::table('ticket_types')->insertGetId(['event_id' => $firstEventId, 'name_en' => 'General', 'price' => 100, 'created_at' => $now, 'updated_at' => $now]);
            }

            // ticket_price_periods may use 'name', 'name_en' or 'label_en'
            if ($colExists('ticket_price_periods', 'name')) {
                $pp = DB::table('ticket_price_periods')->where('ticket_type_id', $ttId)->where('name', 'Early Bird')->first();
                if (!$pp) DB::table('ticket_price_periods')->insert(['ticket_type_id' => $ttId, 'name' => 'Early Bird', 'price' => 80, 'starts_at' => now()->subDays(7)->toDateTimeString(), 'ends_at' => now()->addDays(7)->toDateTimeString(), 'created_at' => $now, 'updated_at' => $now]);
            } elseif ($colExists('ticket_price_periods', 'name_en')) {
                $pp = DB::table('ticket_price_periods')->where('ticket_type_id', $ttId)->where('name_en', 'Early Bird')->first();
                if (!$pp) DB::table('ticket_price_periods')->insert(['ticket_type_id' => $ttId, 'name_en' => 'Early Bird', 'price' => 80, 'starts_at' => now()->subDays(7)->toDateTimeString(), 'ends_at' => now()->addDays(7)->toDateTimeString(), 'created_at' => $now, 'updated_at' => $now]);
            } elseif ($colExists('ticket_price_periods', 'label_en')) {
                $pp = DB::table('ticket_price_periods')->where('ticket_type_id', $ttId)->where('label_en', 'Early Bird')->first();
                if (!$pp) DB::table('ticket_price_periods')->insert(['ticket_type_id' => $ttId, 'label_en' => 'Early Bird', 'price' => 80, 'starts_at' => now()->subDays(7)->toDateTimeString(), 'ends_at' => now()->addDays(7)->toDateTimeString(), 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        // Customer, order, registration, attendee, checkin
        $custEmail = 'qa-user1@local.test';
        $cust = DB::table('users')->where('email', $custEmail)->first();
        if (!$cust) {
            DB::table('users')->insert(['role_id' => DB::table('roles')->where('code', 'customer')->value('id') ?? 4, 'name' => 'QA Customer 1', 'email' => $custEmail, 'username' => 'qacust1', 'password_hash' => Hash::make('Password123!'), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
            $custId = DB::table('users')->where('email', $custEmail)->value('id');
        } else {
            $custId = $cust->id;
        }

        if ($firstEventId) {
            $order = DB::table('orders')->where('order_number', 'QAORD1')->first();
            $orderId = $order ? $order->id : DB::table('orders')->insertGetId(['order_number' => 'QAORD1', 'status' => 'paid', 'total' => 100, 'currency' => 'EGP', 'created_at' => $now, 'updated_at' => $now]);

            $registration = DB::table('registrations')->where('registration_number', 'QAREG1')->first();
            $regId = $registration ? $registration->id : DB::table('registrations')->insertGetId(['registration_number' => 'QAREG1', 'event_id' => $firstEventId, 'ticket_type_id' => $ttId ?? null, 'order_id' => $orderId, 'registration_status' => 'approved', 'payment_status' => 'paid', 'payment_method' => 'card', 'created_at' => $now, 'updated_at' => $now, 'customer_name' => 'QA Customer 1', 'customer_email' => $custEmail]);

            $att = DB::table('attendees')->where('registration_id', $regId)->first();
            $attId = $att ? $att->id : DB::table('attendees')->insertGetId(['event_id' => $firstEventId, 'registration_id' => $regId, 'full_name' => 'QA Customer 1', 'email' => $custEmail, 'created_at' => $now, 'updated_at' => $now]);

            DB::table('checkin_logs')->updateOrInsert(['attendee_id' => $attId, 'event_id' => $firstEventId], ['scanned_by_user_id' => $adminId, 'scan_result' => 'success', 'scanned_at' => $now, 'notes' => 'demo checkin']);
        }

        // Certificates
        if (isset($attId)) {
            $ct = DB::table('certificate_templates')->where('name', 'QA Template')->first();
            if (!$ct) DB::table('certificate_templates')->insert(['event_id' => $firstEventId, 'name' => 'QA Template', 'html_template' => '<h1>Certificate</h1>', 'is_default' => 1, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('certificates')->updateOrInsert(['attendee_id' => $attId, 'event_id' => $firstEventId], ['certificate_number' => 'CERT-QA-1', 'status' => 'issued', 'created_at' => $now, 'updated_at' => $now]);
        }

        // Misc records
        DB::table('admin_notifications')->updateOrInsert(['title_en' => 'QA Data Loaded'], ['type' => 'info', 'title' => 'QA Data', 'title_en' => 'QA Data Loaded', 'message' => 'Demo dataset loaded', 'created_at' => $now, 'updated_at' => $now]);
        // user_notifications schema may vary (message vs message_en / is_read vs read_at)
        if ($colExists('user_notifications', 'message')) {
            DB::table('user_notifications')->updateOrInsert(['user_id' => $custId ?? null, 'type' => 'event', 'message' => 'Welcome to QA'], ['is_read' => 0, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            DB::table('user_notifications')->updateOrInsert(['user_id' => $custId ?? null, 'type' => 'event', 'message_en' => 'Welcome to QA'], ['read_at' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('contact_inquiries')->updateOrInsert(['reference_code' => 'QAC1'], ['full_name' => 'Visitor One', 'email' => 'visitor@local.test', 'subject' => 'Inquiry', 'message' => 'Demo inquiry', 'status' => 'new', 'created_at' => $now, 'updated_at' => $now]);
            // event_brief_requests has varied schemas; insert a minimal compatible row
            $ebrCols = DB::getSchemaBuilder()->getColumnListing('event_brief_requests');
            $ebr = ['created_at' => $now, 'updated_at' => $now];
            if (in_array('email', $ebrCols)) $ebr['email'] = 'brief@local.test';
            if (in_array('full_name', $ebrCols)) $ebr['full_name'] = 'Brief QA';
            if (in_array('event_brief', $ebrCols)) $ebr['event_brief'] = 'demo brief';
            if (in_array('event_name', $ebrCols) && $firstEventId) $ebr['event_name'] = 'QA Event #' . $firstEventId;
            DB::table('event_brief_requests')->insertOrIgnore([$ebr]);
        if ($firstEventId) {
            DB::table('event_cards')->updateOrInsert(['event_id' => $firstEventId, 'name' => 'QA Card'], ['created_at' => $now, 'updated_at' => $now]);
        } else {
            DB::table('event_cards')->updateOrInsert(['name' => 'QA Card'], ['created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('bank_accounts')->updateOrInsert(['account_number' => '000111222'], ['bank_name' => 'QA Bank', 'account_name' => 'QA', 'account_number' => '000111222', 'currency' => 'EGP', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('project_settings')->updateOrInsert(['setting_key' => 'qa.dataset.loaded'], ['setting_value' => '1', 'created_at' => $now, 'updated_at' => $now]);
        if ($firstEventId) {
            DB::table('public_checkout_sessions')->updateOrInsert(['event_id' => $firstEventId, 'response_json' => '{}'], ['created_at' => $now, 'updated_at' => $now]);
        } else {
            DB::table('public_checkout_sessions')->updateOrInsert(['response_json' => '{}'], ['created_at' => $now, 'updated_at' => $now]);
        }

        if (isset($this->command) && $this->command) {
            $this->command->info('LocalDemoSeeder completed.');
        } else {
            echo "LocalDemoSeeder completed.\n";
        }
    }
}
