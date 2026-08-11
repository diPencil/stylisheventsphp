<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PhaseFMeTest extends TestCase
{
    use DatabaseTransactions;

    protected $customerId;
    protected $customerToken;
    protected $otherCustomerId;
    protected $otherCustomerToken;
    protected $eventId;
    protected $ticketTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $role = DB::table('roles')->where('code', 'customer')->first();

        // Create user A
        $this->customerId = DB::table('users')->insertGetId([
            'name' => 'Customer A',
            'email' => 'customer_a_me@test.com',
            'password_hash' => 'hash',
            'status' => 'active',
            'role_id' => $role->id,
        ]);

        $doctorId = DB::table('doctors')->insertGetId([
            'user_id' => $this->customerId,
            'full_name' => 'Customer A',
            'email' => 'customer_a_me@test.com',
            'mobile' => '123123123',
            'country_code' => 'AE',
        ]);

        $userA = User::find($this->customerId);
        $this->customerToken = 'Bearer ' . app('auth')->guard('api')->createToken($userA);

        // Create user B
        $this->otherCustomerId = DB::table('users')->insertGetId([
            'name' => 'Customer B',
            'email' => 'customer_b_me@test.com',
            'password_hash' => 'hash',
            'status' => 'active',
            'role_id' => $role->id,
        ]);

        $otherDoctorId = DB::table('doctors')->insertGetId([
            'user_id' => $this->otherCustomerId,
            'full_name' => 'Customer B',
            'email' => 'customer_b_me@test.com',
            'mobile' => '99999999',
            'country_code' => 'AE',
        ]);

        $userB = User::find($this->otherCustomerId);
        $this->otherCustomerToken = 'Bearer ' . app('auth')->guard('api')->createToken($userB);

        // Create event
        $this->eventId = DB::table('events')->insertGetId([
            'title_en' => 'Me Event',
            'slug' => 'me-event',
            'type' => 'conference',
            'status' => 'published',
            'starts_at' => now()->addDays(5)->toDateTimeString(),
            'ends_at' => now()->addDays(6)->toDateTimeString(),
            'timezone' => 'Asia/Dubai',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ticketTypeId = DB::table('ticket_types')->insertGetId([
            'event_id' => $this->eventId,
            'name_en' => 'Me Ticket',
            'description_en' => 'Desc',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create Order for User A
        $this->orderIdA = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-MEA',
            'event_id' => $this->eventId,
            'customer_name' => 'Customer A',
            'customer_email' => 'customer_a_me@test.com',
            'subtotal' => 0,
            'grand_total' => 0,
            'currency' => 'AED',
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create Registration for User A
        $this->regIdA = DB::table('registrations')->insertGetId([
            'registration_number' => 'REG-MEA',
            'event_id' => $this->eventId,
            'ticket_type_id' => $this->ticketTypeId,
            'doctor_id' => $doctorId,
            'order_id' => $this->orderIdA,
            'source' => 'online',
            'registration_status' => 'approved',
            'payment_status' => 'free',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->attendeeIdA = DB::table('attendees')->insertGetId([
            'event_id' => $this->eventId,
            'ticket_type_id' => $this->ticketTypeId,
            'order_id' => $this->orderIdA,
            'attendee_number' => 'ATT-1',
            'email' => 'customer_a_me@test.com',
            'full_name' => 'Customer A',
            'qr_token' => 'QR-TOKEN-A',
            'qr_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ticketIdA = DB::table('generated_tickets')->insertGetId([
            'registration_id' => $this->regIdA,
            'attendee_id' => $this->attendeeIdA,
            'ticket_number' => 'T-MEA',
            'pdf_url' => '/t.pdf',
            'qr_token' => 'QR-TOKEN-A',
            'generated_at' => now(),
        ]);

        // Create Revoked Ticket for User A
        $this->regIdRevoked = DB::table('registrations')->insertGetId([
            'registration_number' => 'REG-MEREV',
            'event_id' => $this->eventId,
            'ticket_type_id' => $this->ticketTypeId,
            'doctor_id' => $doctorId,
            'order_id' => $this->orderIdA,
            'source' => 'online',
            'registration_status' => 'approved',
            'payment_status' => 'free',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->attendeeIdRevoked = DB::table('attendees')->insertGetId([
            'event_id' => $this->eventId,
            'ticket_type_id' => $this->ticketTypeId,
            'order_id' => $this->orderIdA,
            'attendee_number' => 'ATT-2',
            'email' => 'customer_a_me@test.com',
            'full_name' => 'Customer A',
            'qr_token' => 'QR-TOKEN-REV',
            'qr_status' => 'revoked',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ticketIdRevoked = DB::table('generated_tickets')->insertGetId([
            'registration_id' => $this->regIdRevoked,
            'attendee_id' => $this->attendeeIdRevoked,
            'ticket_number' => 'T-MEREV',
            'pdf_url' => '/t.pdf',
            'qr_token' => 'QR-TOKEN-REV',
            'generated_at' => now(),
        ]);

        $this->certIdA = DB::table('certificates')->insertGetId([
            'attendee_id' => $this->attendeeIdA,
            'certificate_number' => 'CERT-MEA',
            'status' => 'issued',
            'file_url' => '/cert.pdf',
            'issued_at' => now(),
            'created_at' => now(),
        ]);

        $this->reviewIdA = DB::table('reviews')->insertGetId([
            'customer_id' => $this->customerId,
            'attendee_id' => $this->attendeeIdA,
            'event_id' => $this->eventId,
            'rating' => 5,
            'title' => 'Good',
            'comment' => 'Nice',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create Order for User B
        $this->orderIdB = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-MEB',
            'event_id' => $this->eventId,
            'customer_name' => 'Customer B',
            'customer_email' => 'customer_b_me@test.com',
            'subtotal' => 0,
            'grand_total' => 0,
            'currency' => 'AED',
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create Registration for User B
        $this->regIdB = DB::table('registrations')->insertGetId([
            'registration_number' => 'REG-MEB',
            'event_id' => $this->eventId,
            'ticket_type_id' => $this->ticketTypeId,
            'doctor_id' => $otherDoctorId,
            'order_id' => $this->orderIdB,
            'source' => 'online',
            'registration_status' => 'approved',
            'payment_status' => 'free',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->attendeeIdB = DB::table('attendees')->insertGetId([
            'event_id' => $this->eventId,
            'ticket_type_id' => $this->ticketTypeId,
            'order_id' => $this->orderIdB,
            'attendee_number' => 'ATT-3',
            'email' => 'customer_b_me@test.com',
            'full_name' => 'Customer B',
            'qr_token' => 'QR-TOKEN-B',
            'qr_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);


        $this->ticketIdB = DB::table('generated_tickets')->insertGetId([
            'registration_id' => $this->regIdB,
            'attendee_id' => $this->attendeeIdB,
            'ticket_number' => 'T-MEB',
            'pdf_url' => '/tb.pdf',
            'qr_token' => 'QR-TOKEN-B',
            'generated_at' => now(),
        ]);
    }

    public function test_unauthenticated_returns_401()
    {
        $endpoints = [
            '/api/me/dashboard',
            '/api/me/registrations',
            '/api/me/registrations/' . $this->regIdA,
            '/api/me/tickets',
            '/api/me/tickets/' . $this->ticketIdA,
            '/api/me/tickets/' . $this->ticketIdA . '/qr',
            '/api/me/certificates',
            '/api/me/notifications',
            '/api/me/reviews'
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);
            $response->assertStatus(401);
        }
    }

    public function test_dashboard()
    {
        $res = $this->getJson('/api/me/dashboard', ['Authorization' => $this->customerToken]);
        $res->assertStatus(200);
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('data.summary.totalRegistrations', 2);
        $res->assertJsonPath('data.summary.upcomingRegistrations', 2);
        $res->assertJsonPath('data.summary.activeTickets', 1); // 1 active, 1 revoked
        $res->assertJsonPath('data.summary.availableCertificates', 1);
        $this->assertNotNull($res->json('data.nextEvent'));
        $this->assertCount(2, $res->json('data.recentRegistrations'));
    }

    public function test_registrations()
    {
        $res = $this->getJson('/api/me/registrations', ['Authorization' => $this->customerToken]);
        $res->assertStatus(200);
        $this->assertCount(2, $res->json('data.data'));
        $res->assertJsonPath('data.pagination.total', 2);

        // Test other user's registration is denied/not found
        $res2 = $this->getJson("/api/me/registrations/{$this->regIdB}", ['Authorization' => $this->customerToken]);
        $res2->assertStatus(404);

        // Test own registration is found
        $res3 = $this->getJson("/api/me/registrations/{$this->regIdA}", ['Authorization' => $this->customerToken]);
        $res3->assertStatus(200);
        $res3->assertJsonPath('data.id', $this->regIdA);
    }

    public function test_tickets_and_qr()
    {
        $res = $this->getJson('/api/me/tickets', ['Authorization' => $this->customerToken]);
        $res->assertStatus(200);
        $this->assertCount(2, $res->json('data.data'));
        $res->assertJsonPath('data.pagination.total', 2);

        // Test other user's ticket is denied/not found
        $res2 = $this->getJson("/api/me/tickets/{$this->ticketIdB}", ['Authorization' => $this->customerToken]);
        $res2->assertStatus(404);

        // Test own ticket is found
        $res3 = $this->getJson("/api/me/tickets/{$this->ticketIdA}", ['Authorization' => $this->customerToken]);
        $res3->assertStatus(200);
        $res3->assertJsonPath('data.id', $this->ticketIdA);

        // Test QR payload for own active ticket
        $resQr = $this->getJson("/api/me/tickets/{$this->ticketIdA}/qr", ['Authorization' => $this->customerToken]);
        $resQr->assertStatus(200);
        $resQr->assertJsonPath('data.qrPayload', 'QR-TOKEN-A');

        // Test QR payload for revoked ticket returns 409
        $resQrRevoked = $this->getJson("/api/me/tickets/{$this->ticketIdRevoked}/qr", ['Authorization' => $this->customerToken]);
        $resQrRevoked->assertStatus(409);
        $resQrRevoked->assertJsonPath('details.state', 'cancelled');
    }

    public function test_certificates()
    {
        $res = $this->getJson('/api/me/certificates', ['Authorization' => $this->customerToken]);
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data.data'));
        $res->assertJsonPath('data.pagination.total', 1);
    }

    public function test_notifications()
    {
        $res = $this->getJson('/api/me/notifications', ['Authorization' => $this->customerToken]);
        $res->assertStatus(200);
        $this->assertCount(0, $res->json('data.data'));
        $res->assertJsonPath('data.pagination.total', 0);
    }

    public function test_reviews()
    {
        $res = $this->getJson('/api/me/reviews', ['Authorization' => $this->customerToken]);
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data.data'));
        $res->assertJsonPath('data.data.0.id', $this->reviewIdA);
        $res->assertJsonPath('data.pagination.total', 1);
    }
}
