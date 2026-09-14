<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use App\Models\User;
use App\Mail\CertificateDeliveryMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;

class PhaseGTest extends TestCase
{
    use DatabaseTransactions;

    protected $admin;
    protected $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $roleIdAdmin = DB::table('roles')->where('code', 'admin')->value('id');
        $adminId = DB::table('users')->insertGetId([
            'role_id' => $roleIdAdmin,
            'name' => 'Admin User',
            'email' => 'admin.test.phaseg@test.com',
            'password_hash' => 'hash',
            'status' => 'active'
        ]);
        $this->admin = User::find($adminId);
        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $roleIdAdmin, 'permission_key' => 'certificates.manage'],
            ['allowed' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $roleIdAdmin, 'permission_key' => 'website_content.manage'],
            ['allowed' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $roleIdAdmin, 'permission_key' => 'reports.view'],
            ['allowed' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $roleIdAdmin, 'permission_key' => 'settings.manage'],
            ['allowed' => 1, 'created_at' => now(), 'updated_at' => now()]
        );

        $roleIdEmployee = DB::table('roles')->where('code', 'employee')->value('id');
        $empId = DB::table('users')->insertGetId([
            'role_id' => $roleIdEmployee,
            'name' => 'Emp User',
            'email' => 'emp.test.phaseg@test.com',
            'password_hash' => 'hash',
            'status' => 'active'
        ]);
        $this->employee = User::find($empId);
    }

    public function test_platform_overview()
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/api/platform/overview');
        $response->assertStatus(200)
                 ->assertJsonStructure(['status', 'data' => ['stats' => ['events', 'publishedEvents', 'orders', 'attendees', 'checkedIn', 'revenue', 'pendingReviews'], 'upcomingEvents']]);
    }

    public function test_public_events_alias_lists_events()
    {
        $response = $this->getJson('/api/public/events?limit=5');

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure(['success', 'message', 'data']);
    }

    public function test_protected_api_without_accept_header_returns_json_401()
    {
        $response = $this->get('/api/me/dashboard');

        $response->assertStatus(401)
                 ->assertJsonPath('success', false)
                 ->assertJsonPath('message', 'Unauthenticated');
    }

    public function test_doctors_lookup_profile()
    {
        $this->getJson('/api/doctors/lookup/profile?identity=test@test.com')->assertStatus(404);
    }

    public function test_contact_inquiries_store_and_read()
    {
        // Store
        $postData = [
            'fullName' => 'John Doe',
            'email' => 'john@test.com',
            'phone' => '+1234567890',
            'inquiryType' => 'general',
            'subject' => 'test subject',
            'message' => 'Hello there, this is a very long message for testing!',
            'consentAccepted' => true
        ];

        $response = $this->postJson('/api/contact-inquiries', $postData);
        $response->assertStatus(201)
                 ->assertJsonPath('success', true);

        $ref = $response->json('data.referenceCode');

        // Read list
        $listResponse = $this->actingAs($this->admin, 'api')->getJson('/api/contact-inquiries');
        $listResponse->assertStatus(200);
    }

    public function test_contact_inquiries_update_does_not_require_audit_logger_binding()
    {
        $id = DB::table('contact_inquiries')->insertGetId([
            'reference_code' => 'INQ-TEST-' . uniqid(),
            'full_name' => 'Jane Doe',
            'email' => 'jane-' . uniqid() . '@test.com',
            'inquiry_type' => 'general',
            'subject' => 'test subject',
            'message' => 'Hello there, this is a very long message for testing!',
            'status' => 'new',
            'source_page' => '/contact',
            'consent_accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/contact-inquiries/{$id}", [
                'status' => 'resolved',
                'adminNotes' => 'Handled in test',
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'resolved');

        $this->assertDatabaseHas('contact_inquiries', [
            'id' => $id,
            'status' => 'resolved',
            'admin_notes' => 'Handled in test',
        ]);
    }

    public function test_event_brief_requests()
    {
        $postData = [
            'bookingType' => 'single',
            'fullName' => 'Test Booking',
            'email' => 'booking@test.com',
            'countryCode' => '+1',
            'phone' => '123456',
            'preferredContactMethod' => 'email',
            'jobTitle' => 'CEO',
            'isDateFlexible' => false,
            'venueStatus' => 'known',
            'eventBrief' => 'This is a long enough event brief for testing.',
            'privacyConsent' => true,
            'communicationConsent' => true,
            'language' => 'en'
        ];

        $response = $this->postJson('/api/booking', $postData);
        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        $ref = $response->json('referenceNumber');
        $this->assertDatabaseHas('event_brief_requests', ['reference_number' => $ref]);
    }

    public function test_email_settings_can_disable_signup_verification()
    {
        Cache::forget('project_settings:email_settings');

        $this->actingAs($this->admin, 'api')
            ->putJson('/api/platform/settings/email', [
                'auth' => ['emailVerificationEnabled' => false],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.auth.emailVerificationEnabled', false);

        $this->actingAs($this->admin, 'api')
            ->getJson('/api/platform/settings/email')
            ->assertStatus(200)
            ->assertJsonPath('data.auth.emailVerificationEnabled', false);

        $this->assertDatabaseHas('project_settings', ['setting_key' => 'email_settings']);
        Cache::forget('project_settings:email_settings');
    }

    public function test_certificates_templates()
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/api/certificates/templates');
        $response->assertStatus(200);
    }

    public function test_certificate_multi_template_assignment_and_default_switch()
    {
        $eventA = DB::table('events')->insertGetId([
            'organizer_id' => $this->admin->id,
            'slug' => 'phaseg-multi-a-' . uniqid(),
            'title_en' => 'Multi Template Event A',
            'title_ar' => 'فعالية أ',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $eventB = DB::table('events')->insertGetId([
            'organizer_id' => $this->admin->id,
            'slug' => 'phaseg-multi-b-' . uniqid(),
            'title_en' => 'Multi Template Event B',
            'title_ar' => 'فعالية ب',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketId = DB::table('ticket_types')->insertGetId([
            'event_id' => $eventA,
            'name_en' => 'Multi Ticket',
            'name_ar' => 'تذكرة',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'event_id' => $eventA,
            'order_number' => 'ORD-MULTI-' . uniqid(),
            'status' => 'paid',
            'customer_name' => 'Multi Attendee',
            'customer_email' => 'multi-' . uniqid() . '@test.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attendeeId = DB::table('attendees')->insertGetId([
            'order_id' => $orderId,
            'event_id' => $eventA,
            'ticket_type_id' => $ticketId,
            'attendee_number' => 'ATT-MULTI-' . uniqid(),
            'full_name' => 'Multi Attendee',
            'email' => 'multi-attendee-' . uniqid() . '@test.com',
            'qr_token' => str_repeat('e', 63) . '5',
            'qr_status' => 'used',
            'checked_in_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $templateA1 = DB::table('certificate_templates')->insertGetId([
            'event_id' => $eventA, 'name' => 'Speaker Design', 'template_type' => 'image',
            'template_url' => '/uploads/a1.png', 'is_default' => 1, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $templateA2 = DB::table('certificate_templates')->insertGetId([
            'event_id' => $eventA, 'name' => 'Attendee Design', 'template_type' => 'image',
            'template_url' => '/uploads/a2.png', 'is_default' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $templateB = DB::table('certificate_templates')->insertGetId([
            'event_id' => $eventB, 'name' => 'Other Event Design', 'template_type' => 'image',
            'template_url' => '/uploads/b.png', 'is_default' => 1, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Template from another event must be rejected.
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/certificates/issue', ['attendeeId' => $attendeeId, 'templateKey' => (string) $templateB])
            ->assertStatus(422);

        // Switch default within the event.
        $this->actingAs($this->admin, 'api')
            ->postJson("/api/certificates/templates/{$templateA2}/default")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
        $this->assertEquals(0, (int) DB::table('certificate_templates')->where('id', $templateA1)->value('is_default'));
        $this->assertEquals(1, (int) DB::table('certificate_templates')->where('id', $templateA2)->value('is_default'));

        // Issue with the explicit template choice (manual assignment).
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/certificates/issue', ['attendeeId' => $attendeeId, 'templateKey' => (string) $templateA1])
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('certificates', [
            'attendee_id' => $attendeeId,
            'template_key' => (string) $templateA1,
        ]);

        // Delivery exposes the stored template name.
        $delivery = $this->actingAs($this->admin, 'api')
            ->getJson("/api/certificates/delivery?eventId={$eventA}")
            ->assertStatus(200)
            ->json('data');
        $row = collect($delivery)->firstWhere('attendee_id', $attendeeId);
        $this->assertNotNull($row);
        $this->assertEquals($templateA1, (int) $row['certificate_template_id']);
        $this->assertEquals('Speaker Design', $row['certificate_template_name']);

        // Deleting a template used by issued certificates is refused...
        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/certificates/templates/{$templateA1}")
            ->assertStatus(409);

        // ...while an unused one deletes cleanly.
        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/certificates/templates/{$templateB}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
        $this->assertDatabaseMissing('certificate_templates', ['id' => $templateB]);
    }

    public function test_event_card_multi_design_assignment_and_default_switch()
    {
        $eventA = DB::table('events')->insertGetId([
            'organizer_id' => $this->admin->id,
            'slug' => 'phaseg-card-a-' . uniqid(),
            'title_en' => 'Card Design Event A',
            'title_ar' => 'فعالية كارت أ',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $eventB = DB::table('events')->insertGetId([
            'organizer_id' => $this->admin->id,
            'slug' => 'phaseg-card-b-' . uniqid(),
            'title_en' => 'Card Design Event B',
            'title_ar' => 'فعالية كارت ب',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketId = DB::table('ticket_types')->insertGetId([
            'event_id' => $eventA,
            'name_en' => 'Card Ticket',
            'name_ar' => 'تذكرة كارت',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'event_id' => $eventA,
            'order_number' => 'ORD-CARD-' . uniqid(),
            'status' => 'paid',
            'customer_name' => 'Card Attendee',
            'customer_email' => 'card-' . uniqid() . '@test.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attendeeId = DB::table('attendees')->insertGetId([
            'order_id' => $orderId,
            'event_id' => $eventA,
            'ticket_type_id' => $ticketId,
            'attendee_number' => 'ATT-CARD-' . uniqid(),
            'full_name' => 'Card Attendee',
            'email' => 'card-attendee-' . uniqid() . '@test.com',
            'qr_token' => str_repeat('d', 63) . '4',
            'qr_status' => 'used',
            'checked_in_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create two designs via API; second becomes default.
        $design1 = $this->actingAs($this->admin, 'api')
            ->postJson('/api/event-cards/templates', [
                'eventId' => $eventA, 'name' => 'VIP Card', 'backgroundUrl' => '/uploads/vip.png',
                'fields' => ['texts' => ['header' => 'VIP Access'], 'visibility' => ['qr' => true]],
                'isDefault' => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->json('data.id');
        $design2 = $this->actingAs($this->admin, 'api')
            ->postJson('/api/event-cards/templates', [
                'eventId' => $eventA, 'name' => 'Standard Card',
                'fields' => ['visibility' => ['qr' => false]],
                'isDefault' => false,
            ])
            ->assertStatus(200)
            ->json('data.id');
        $foreign = DB::table('event_card_templates')->insertGetId([
            'event_id' => $eventB, 'name' => 'Foreign Card', 'is_default' => 1, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Listing shows both, default first.
        $list = $this->actingAs($this->admin, 'api')
            ->getJson("/api/event-cards/templates?eventId={$eventA}")
            ->assertStatus(200)
            ->json('data');
        $this->assertCount(2, $list);
        $this->assertEquals($design1, (int) $list[0]['id']);

        // Foreign design rejected on generate.
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/certificates/event-card', ['attendeeId' => $attendeeId, 'templateKey' => (string) $foreign])
            ->assertStatus(422);

        // Generate with explicit design choice (manual assignment).
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/certificates/event-card', ['attendeeId' => $attendeeId, 'templateKey' => (string) $design2])
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('event_cards', [
            'attendee_id' => $attendeeId,
            'event_id' => $eventA,
            'template_key' => (string) $design2,
        ]);

        // Delivery exposes the stored card design name.
        $delivery = $this->actingAs($this->admin, 'api')
            ->getJson("/api/certificates/delivery?eventId={$eventA}")
            ->assertStatus(200)
            ->json('data');
        $row = collect($delivery)->firstWhere('attendee_id', $attendeeId);
        $this->assertNotNull($row);
        $this->assertEquals($design2, (int) $row['card_template_id']);
        $this->assertEquals('Standard Card', $row['card_template_name']);

        // Switch default; delete guard blocks used design, allows unused.
        $this->actingAs($this->admin, 'api')
            ->postJson("/api/event-cards/templates/{$design2}/default")
            ->assertStatus(200);
        $this->assertEquals(1, (int) DB::table('event_card_templates')->where('id', $design2)->value('is_default'));
        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/event-cards/templates/{$design2}")
            ->assertStatus(409);
        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/event-cards/templates/{$design1}")
            ->assertStatus(200);
        $this->assertDatabaseMissing('event_card_templates', ['id' => $design1]);
    }

    public function test_card_template_setting_and_image_upload()
    {
        $uploadResponse = $this->actingAs($this->admin, 'api')
            ->postJson('/api/platform/assets/upload', [
                'fileName' => 'card-template-test.png',
                'dataUrl' => 'data:image/png;base64,' . base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=')),
            ]);

        $uploadResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $url = $uploadResponse->json('data.url');
        $this->assertNotEmpty($url);

        $this->actingAs($this->admin, 'api')
            ->putJson('/api/platform/settings/card-template', ['imageUrl' => $url])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.imageUrl', $url);

        $this->getJson('/api/platform/settings/card-template')
            ->assertStatus(200)
            ->assertJsonPath('data.imageUrl', $url);

        $this->actingAs($this->admin, 'api')
            ->putJson('/api/platform/settings/card-template', ['imageUrl' => ''])
            ->assertStatus(200)
            ->assertJsonPath('data.imageUrl', '');

        $path = public_path('uploads/assets/' . basename($url));
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function test_certificate_and_event_card_generation_match_current_schema()
    {
        $eventId = DB::table('events')->insertGetId([
            'organizer_id' => $this->admin->id,
            'slug' => 'phaseg-cert-' . uniqid(),
            'title_en' => 'Phase G Certificate Event',
            'title_ar' => 'Phase G Certificate Event AR',
            'status' => 'published',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketId = DB::table('ticket_types')->insertGetId([
            'event_id' => $eventId,
            'name_en' => 'Certificate Ticket',
            'name_ar' => 'Certificate Ticket AR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = DB::table('orders')->insertGetId([
            'event_id' => $eventId,
            'order_number' => 'ORD-PHG-' . uniqid(),
            'status' => 'paid',
            'customer_name' => 'Certificate Test',
            'customer_email' => 'certificate-' . uniqid() . '@test.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attendeeId = DB::table('attendees')->insertGetId([
            'order_id' => $orderId,
            'event_id' => $eventId,
            'ticket_type_id' => $ticketId,
            'attendee_number' => 'ATT-PHG-' . uniqid(),
            'full_name' => 'Certificate Test',
            'email' => 'certificate-attendee-' . uniqid() . '@test.com',
            'qr_token' => str_repeat('a', 63) . '1',
            'qr_status' => 'used',
            'checked_in_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Full attendance: one daily check-in for each of the 2 event days.
        foreach ([now()->addDay(), now()->addDays(2)] as $day) {
            DB::table('attendee_daily_checkins')->insert([
                'attendee_id' => $attendeeId,
                'event_id' => $eventId,
                'checkin_date' => $day->toDateString(),
                'first_checked_in_at' => $day,
                'last_checked_in_at' => $day,
                'first_scanned_by_user_id' => $this->admin->id,
                'last_scanned_by_user_id' => $this->admin->id,
                'first_source' => 'manual',
                'last_source' => 'manual',
                'checkin_count' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/certificates/issue', ['attendeeId' => $attendeeId, 'templateKey' => 'default'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/certificates/event-card', ['attendeeId' => $attendeeId, 'templateKey' => 'default'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_certificate_issue_requires_all_event_days()
    {        $eventId = DB::table('events')->insertGetId([
            'organizer_id' => $this->admin->id,
            'slug' => 'phaseg-cert-partial-' . uniqid(),
            'title_en' => 'Phase G Partial Attendance Event',
            'title_ar' => 'فعالية حضور جزئي',
            'status' => 'published',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketId = DB::table('ticket_types')->insertGetId([
            'event_id' => $eventId,
            'name_en' => 'Partial Ticket',
            'name_ar' => 'تذكرة جزئية',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = DB::table('orders')->insertGetId([
            'event_id' => $eventId,
            'order_number' => 'ORD-PARTIAL-' . uniqid(),
            'status' => 'paid',
            'customer_name' => 'Partial Attendee',
            'customer_email' => 'partial-' . uniqid() . '@test.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attendeeId = DB::table('attendees')->insertGetId([
            'order_id' => $orderId,
            'event_id' => $eventId,
            'ticket_type_id' => $ticketId,
            'attendee_number' => 'ATT-PARTIAL-' . uniqid(),
            'full_name' => 'Partial Attendee',
            'email' => 'partial-attendee-' . uniqid() . '@test.com',
            'qr_token' => str_repeat('b', 63) . '2',
            'qr_status' => 'used',
            'checked_in_at' => now()->subDays(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Only 1 of the 2 event days attended.
        DB::table('attendee_daily_checkins')->insert([
            'attendee_id' => $attendeeId,
            'event_id' => $eventId,
            'checkin_date' => now()->subDays(2)->toDateString(),
            'first_checked_in_at' => now()->subDays(2),
            'last_checked_in_at' => now()->subDays(2),
            'first_scanned_by_user_id' => $this->admin->id,
            'last_scanned_by_user_id' => $this->admin->id,
            'first_source' => 'manual',
            'last_source' => 'manual',
            'checkin_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/certificates/issue', ['attendeeId' => $attendeeId, 'templateKey' => 'default'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('details.requiredDays', 2)
            ->assertJsonPath('details.daysAttended', 1);
    }

    private function createCertificateRecipient(array $overrides = []): array
    {
        $eventId = DB::table('events')->insertGetId([
            'organizer_id' => $this->admin->id,
            'slug' => $overrides['slug'] ?? 'phaseg-mail-' . uniqid(),
            'title_en' => $overrides['eventTitleEn'] ?? 'Phase G Mail Event',
            'title_ar' => $overrides['eventTitleAr'] ?? 'فعالية شهادات',
            'status' => 'published',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketId = DB::table('ticket_types')->insertGetId([
            'event_id' => $eventId,
            'name_en' => 'Mail Ticket',
            'name_ar' => 'تذكرة البريد',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $email = array_key_exists('email', $overrides) ? $overrides['email'] : 'mail-' . uniqid() . '@test.com';

        $orderId = DB::table('orders')->insertGetId([
            'event_id' => $eventId,
            'order_number' => 'ORD-MAIL-' . uniqid(),
            'status' => 'paid',
            'customer_name' => $overrides['name'] ?? 'Mail Recipient',
            'customer_email' => $email ?: 'missing-' . uniqid() . '@test.invalid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attendeeId = DB::table('attendees')->insertGetId([
            'order_id' => $orderId,
            'event_id' => $eventId,
            'ticket_type_id' => $ticketId,
            'attendee_number' => 'ATT-MAIL-' . uniqid(),
            'full_name' => $overrides['name'] ?? 'Mail Recipient',
            'email' => $email,
            'qr_token' => str_pad(uniqid('', true), 64, 'a'),
            'qr_status' => 'used',
            'checked_in_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $certificateId = DB::table('certificates')->insertGetId([
            'attendee_id' => $attendeeId,
            'certificate_number' => $overrides['certificateNumber'] ?? 'CERT-MAIL-' . strtoupper(substr(uniqid(), -6)),
            'template_key' => 'default',
            'file_url' => null,
            'status' => $overrides['status'] ?? 'issued',
            'issued_at' => now(),
            'created_at' => now(),
        ]);

        return [
            'eventId' => $eventId,
            'attendeeId' => $attendeeId,
            'certificateId' => $certificateId,
            'email' => $email,
            'name' => $overrides['name'] ?? 'Mail Recipient',
            'certificateNumber' => $overrides['certificateNumber'] ?? DB::table('certificates')->where('id', $certificateId)->value('certificate_number'),
        ];
    }

    public function test_authorized_bulk_certificate_email_sends_each_own_certificate()
    {
        Mail::fake();

        $alice = $this->createCertificateRecipient([
            'name' => 'Alice Certificate Owner',
            'email' => 'alice-' . uniqid() . '@test.com',
            'certificateNumber' => 'CERT-ALICE-' . strtoupper(substr(uniqid(), -5)),
        ]);
        $bob = $this->createCertificateRecipient([
            'name' => 'Bob Certificate Owner',
            'email' => 'bob-' . uniqid() . '@test.com',
            'certificateNumber' => 'CERT-BOB-' . strtoupper(substr(uniqid(), -5)),
        ]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/certificates/email/bulk', [
                'certificateIds' => [$alice['certificateId'], $bob['certificateId']],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.summary.selected', 2)
            ->assertJsonPath('data.summary.sent', 2)
            ->assertJsonPath('data.summary.failed', 0)
            ->assertJsonPath('data.summary.missingEmail', 0);

        Mail::assertSent(CertificateDeliveryMail::class, 2);
        Mail::assertSent(CertificateDeliveryMail::class, function (CertificateDeliveryMail $mail) use ($alice, $bob) {
            return $mail->certificate['email'] === $alice['email']
                && $mail->certificate['recipientName'] === $alice['name']
                && $mail->certificate['certificateNumber'] === $alice['certificateNumber']
                && $mail->certificate['certificateNumber'] !== $bob['certificateNumber'];
        });
        Mail::assertSent(CertificateDeliveryMail::class, function (CertificateDeliveryMail $mail) use ($alice, $bob) {
            return $mail->certificate['email'] === $bob['email']
                && $mail->certificate['recipientName'] === $bob['name']
                && $mail->certificate['certificateNumber'] === $bob['certificateNumber']
                && $mail->certificate['certificateNumber'] !== $alice['certificateNumber'];
        });

        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.bulk_email']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.email_sent', 'entity_id' => (string) $alice['certificateId']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.email_sent', 'entity_id' => (string) $bob['certificateId']]);
    }

    public function test_single_certificate_email_and_duplicate_ids_are_safe()
    {
        Mail::fake();

        $recipient = $this->createCertificateRecipient();

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/certificates/email/bulk', [
                'certificateIds' => [$recipient['certificateId'], $recipient['certificateId']],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.summary.selected', 1)
            ->assertJsonPath('data.summary.sent', 1);

        Mail::assertSent(CertificateDeliveryMail::class, 1);
    }

    public function test_certificate_email_uses_configured_brand_and_frontend_url()
    {
        Mail::fake();
        config()->set('app.frontend_url', 'https://stylishmice.com');
        config()->set('mail.from.name', 'Stylish Holidays');

        $recipient = $this->createCertificateRecipient([
            'name' => 'Production Link Owner',
            'email' => 'production-link-' . uniqid() . '@test.com',
            'eventTitleEn' => 'Production URL Event',
        ]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/certificates/email/bulk', [
                'certificateIds' => [$recipient['certificateId']],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.summary.sent', 1);

        Mail::assertSent(CertificateDeliveryMail::class, function (CertificateDeliveryMail $mail) {
            $envelope = $mail->envelope();

            return $mail->certificate['brandName'] === 'Stylish Holidays'
                && $mail->certificate['certificateUrl'] === 'https://stylishmice.com/dashboard/certificates'
                && $envelope->from->name === 'Stylish Holidays'
                && $envelope->subject === 'Your Certificate ' . "\u{2013}" . ' Production URL Event'
                && !str_contains($mail->certificate['certificateUrl'], 'loca.lt')
                && !str_contains($mail->certificate['certificateUrl'], 'localhost')
                && !str_contains($mail->certificate['certificateUrl'], '127.0.0.1');
        });
    }

    public function test_certificate_email_handles_missing_invalid_nonexistent_and_ineligible()
    {
        Mail::fake();

        $valid = $this->createCertificateRecipient(['email' => 'valid-' . uniqid() . '@test.com']);
        $missing = $this->createCertificateRecipient(['email' => '']);
        $invalid = $this->createCertificateRecipient(['email' => 'not-an-email']);
        $pending = $this->createCertificateRecipient(['email' => 'pending-' . uniqid() . '@test.com', 'status' => 'pending']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/certificates/email/bulk', [
                'certificateIds' => [$valid['certificateId'], $missing['certificateId'], $invalid['certificateId'], $pending['certificateId'], 999999999],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.summary.selected', 5)
            ->assertJsonPath('data.summary.sent', 1)
            ->assertJsonPath('data.summary.failed', 2)
            ->assertJsonPath('data.summary.missingEmail', 2);

        Mail::assertSent(CertificateDeliveryMail::class, 1);
    }

    public function test_certificate_email_rejects_event_mismatch()
    {
        Mail::fake();

        $recipient = $this->createCertificateRecipient(['email' => 'event-mismatch-' . uniqid() . '@test.com']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/certificates/email/bulk', [
                'eventId' => $recipient['eventId'] + 9999,
                'certificateIds' => [$recipient['certificateId']],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.summary.sent', 0)
            ->assertJsonPath('data.summary.failed', 1);

        Mail::assertNothingSent();
    }

    public function test_certificate_email_permissions()
    {
        $recipient = $this->createCertificateRecipient(['email' => 'secure-' . uniqid() . '@test.com']);

        $this->postJson('/api/certificates/email/bulk', [
            'certificateIds' => [$recipient['certificateId']],
        ])->assertStatus(401);

        $this->actingAs($this->employee, 'api')
            ->postJson('/api/certificates/email/bulk', [
                'certificateIds' => [$recipient['certificateId']],
            ])->assertStatus(403);

        $roleIdCustomer = DB::table('roles')->where('code', 'customer')->value('id');
        $customerId = DB::table('users')->insertGetId([
            'role_id' => $roleIdCustomer,
            'name' => 'Customer User',
            'email' => 'customer.certmail.' . uniqid() . '@test.com',
            'password_hash' => 'hash',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs(User::find($customerId), 'api')
            ->postJson('/api/certificates/email/bulk', [
                'certificateIds' => [$recipient['certificateId']],
            ])->assertStatus(403);
    }

    public function test_kiosk_search()
    {
        $this->postJson('/api/kiosk/search', ['searchType' => 'email', 'searchValue' => 'John'])
            ->assertStatus(401);

        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $this->admin->role_id, 'permission_key' => 'kiosk.use'],
            ['allowed' => 1, 'created_at' => now(), 'updated_at' => now()]
        );

        $this->actingAs($this->employee, 'api')
            ->postJson('/api/kiosk/search', ['searchType' => 'email', 'searchValue' => 'John'])
            ->assertStatus(403);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/kiosk/search', ['searchType' => 'email', 'searchValue' => 'John'])
            ->assertStatus(404);
    }

    public function test_public_event_review_write_requires_auth()
    {
        $slug = DB::table('events')->where('status', 'published')->value('slug') ?? 'missing-event';

        $this->postJson("/api/public/events/{$slug}/review", [
            'rating' => 5,
            'comment' => 'Great event',
        ])->assertStatus(401);

        $this->patchJson("/api/public/events/{$slug}/review", [
            'rating' => 4,
            'comment' => 'Updated review',
        ])->assertStatus(401);
    }

    public function test_reports_summary()
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/api/reports/summary');
        $response->assertStatus(200)
                 ->assertJsonStructure(['status', 'data' => ['registrations', 'payments', 'revenue', 'certificates']]);
    }

    public function test_reports_attendance_supports_daily_filter()
    {
        $eventId = DB::table('events')->insertGetId([
            'slug' => 'report-attendance-' . uniqid(),
            'title_en' => 'Report Attendance Event',
            'status' => 'published',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'max_attendees' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $checkedAttendeeId = DB::table('attendees')->insertGetId([
            'event_id' => $eventId,
            'full_name' => 'Checked Attendee',
            'email' => 'checked-attendee@example.test',
            'qr_status' => 'used',
            'checked_in_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('attendees')->insert([
            'event_id' => $eventId,
            'full_name' => 'Waiting Attendee',
            'email' => 'waiting-attendee@example.test',
            'qr_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('checkin_logs')->insert([
            [
                'attendee_id' => $checkedAttendeeId,
                'event_id' => $eventId,
                'scanned_by_user_id' => $this->admin->id,
                'scan_result' => 'accepted',
                'scanned_at' => now(),
                'notes' => "source:manual",
            ],
            [
                'attendee_id' => $checkedAttendeeId,
                'event_id' => $eventId,
                'scanned_by_user_id' => $this->admin->id,
                'scan_result' => 'duplicate',
                'scanned_at' => now()->subDay(),
                'notes' => "source:scan",
            ],
        ]);
        DB::table('attendee_daily_checkins')->insert([
            'attendee_id' => $checkedAttendeeId,
            'event_id' => $eventId,
            'checkin_date' => now()->toDateString(),
            'first_checked_in_at' => now(),
            'last_checked_in_at' => now(),
            'first_scanned_by_user_id' => $this->admin->id,
            'last_scanned_by_user_id' => $this->admin->id,
            'first_source' => 'manual',
            'last_source' => 'manual',
            'checkin_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/reports/attendance?date=' . now()->toDateString());

        $response->assertStatus(200)->assertJsonPath('status', 'success');
        $row = collect($response->json('data'))->firstWhere('event_id', $eventId);
        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row['total_attendees']);
        $this->assertSame(1, (int) $row['total_checked_in']);
        $this->assertSame(1, (int) $row['range_checked_in']);
        $this->assertSame(1, (int) $row['attendance_days']);
        $this->assertSame(1, (int) $row['accepted_scans']);
        $this->assertSame(0, (int) $row['duplicate_scans']);
        $this->assertSame(1, (int) $row['manual_scans']);
        $this->assertSame(0, (int) $row['qr_scans']);
    }

    public function test_reports_registrations_include_readable_role()
    {
        $doctorRoleId = DB::table('roles')->where('code', 'doctor')->value('id');
        $this->assertNotNull($doctorRoleId);

        $doctorUserId = DB::table('users')->insertGetId([
            'role_id' => $doctorRoleId,
            'name' => 'Report Doctor',
            'email' => 'report-doctor-' . uniqid() . '@test.com',
            'password_hash' => 'hash',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $eventId = DB::table('events')->insertGetId([
            'slug' => 'report-role-' . uniqid(),
            'title_en' => 'Report Role Event',
            'status' => 'published',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ticketTypeId = DB::table('ticket_types')->insertGetId([
            'event_id' => $eventId,
            'name_en' => 'Report Role Ticket',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $doctorId = DB::table('doctors')->insertGetId([
            'user_id' => $doctorUserId,
            'full_name' => 'Report Doctor',
            'email' => 'report-doctor-' . uniqid() . '@test.com',
            'mobile' => '01000000000',
            'country_code' => 'EG',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'customer_id' => $doctorUserId,
            'event_id' => $eventId,
            'order_number' => 'ORD-ROLE-' . uniqid(),
            'status' => 'paid',
            'subtotal' => 0,
            'grand_total' => 0,
            'currency' => 'USD',
            'customer_name' => 'Report Doctor',
            'customer_email' => 'report-doctor@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('registrations')->insert([
            'registration_number' => 'REG-ROLE-' . uniqid(),
            'doctor_id' => $doctorId,
            'event_id' => $eventId,
            'ticket_type_id' => $ticketTypeId,
            'order_id' => $orderId,
            'source' => 'online',
            'registration_status' => 'approved',
            'payment_status' => 'approved',
            'selected_currency' => 'USD',
            'selected_price' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'api')
            ->getJson('/api/reports/registrations?eventId=' . $eventId)
            ->assertStatus(200)
            ->assertJsonPath('data.0.customer_role_code', 'doctor')
            ->assertJsonPath('data.0.customer_role_name_en', 'Doctor');
    }

    public function test_reports_nationalities()
    {
        $response = $this->actingAs($this->admin, 'api')->getJson('/api/reports/nationalities');
        $response->assertStatus(200);
    }
}
