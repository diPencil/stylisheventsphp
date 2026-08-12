<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class PhaseDCheckoutTest extends TestCase
{
    use DatabaseTransactions;

    private $eventId;
    private $ticketId;
    private $slug = 'test-event-checkout';
    private $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Create published event
        $this->eventId = DB::table('events')->insertGetId([
            'slug' => $this->slug,
            'title_en' => 'Test Checkout Event',
            'title_ar' => 'Test Checkout Event AR',
            'status' => 'published',
            'public_registration_enabled' => 1,
            'registration_approval_mode' => 'automatic',
            'registration_access' => 'guest_allowed',
            'max_tickets_per_checkout' => 4,
            'max_attendees' => 10,
            'capacity_hold_hours_override' => 24,
            'manual_payment_enabled' => 1,
            'starts_at' => now()->addDays(1),
            'ends_at' => now()->addDays(2),
            'registration_starts_at' => now()->subDay(),
            'registration_ends_at' => now()->addDays(1),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ticketId = DB::table('ticket_types')->insertGetId([
            'event_id' => $this->eventId,
            'name_en' => 'Standard Checkout Ticket',
            'name_ar' => 'Standard Checkout Ticket AR',
            'is_active' => 1,
            'quota' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ticket_price_periods')->insert([
            'ticket_type_id' => $this->ticketId,
            'label_en' => 'Standard',
            'label_ar' => 'Standard',
            'price' => 100,
            'price_egp' => 1500,
            'price_usd' => 100,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
            'is_active' => 1,
        ]);
    }

    public function test_checkout_validation_error()
    {
        $response = $this->postJson("/api/public/events/{$this->slug}/checkout", []);
        $response->assertStatus(400)
                 ->assertJsonPath('success', false)
                 ->assertJsonStructure(['success', 'message', 'details' => ['fieldErrors']]);
    }

    public function test_checkout_event_not_found()
    {
        $payload = [
            'idempotencyKey' => 'sess_' . time(),
            'ticketTypeId' => $this->ticketId,
            'quantity' => 1,
            'email' => 'customer@test.com',
            'fullName' => 'John Doe',
            'mobile' => '01000000000',
            'countryCode' => 'EG',
            'countryName' => 'Egypt'
        ];
        $response = $this->postJson("/api/public/events/invalid-slug/checkout", $payload);
        $response->assertStatus(404);
    }

    public function test_successful_paid_checkout()
    {
        $idempotencyKey = 'sess_paid_' . time();
        $payload = [
            'idempotencyKey' => $idempotencyKey,
            'ticketTypeId' => $this->ticketId,
            'quantity' => 1,
            'email' => 'customer_paid@test.com',
            'fullName' => 'John Paid',
            'mobile' => '01000000001',
            'countryCode' => 'US',
            'countryName' => 'United States',
        ];

        $response = $this->postJson("/api/public/events/{$this->slug}/checkout", $payload);
        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure(['data' => ['registration' => ['registration_number', 'registration_status', 'payment_status', 'event_title_en', 'ticket_name_en']]])
                 ->assertJsonPath('data.registration.registrationNumber', $response->json('data.registration.registration_number'))
                 ->assertJsonPath('data.checkout.currency', 'USD')
                 ->assertJsonPath('data.checkout.price', 100);

        $registrationId = $response->json('data.registration.id');
        $this->assertNotNull($registrationId);

        // Check DB for order and registration
        $registration = DB::table('registrations')->where('id', $registrationId)->first();
        $this->assertEquals('pending_payment', $registration->registration_status);
        $this->assertEquals('pending', $registration->payment_status);
        $this->assertEquals(100, $registration->selected_price);
        $this->assertEquals('USD', $registration->selected_currency);

        $order = DB::table('orders')->where('id', $registration->order_id)->first();
        $this->assertEquals('pending_payment', $order->status);
        $this->assertEquals(100, $order->subtotal);

        // Check idempotency repetition
        $response2 = $this->postJson("/api/public/events/{$this->slug}/checkout", $payload);
        $response2->assertStatus(200)
                  ->assertJsonPath('data.repeated', true);
    }

    public function test_free_checkout_issues_ticket()
    {
        // Add free price period overriding the first one
        DB::table('ticket_price_periods')->insert([
            'ticket_type_id' => $this->ticketId,
            'label_en' => 'Free',
            'label_ar' => 'Free',
            'price' => 0,
            'price_egp' => 0,
            'price_usd' => 0,
            'starts_at' => now(),
            'ends_at' => now()->addDays(5),
            'is_active' => 1,
        ]);

        $payload = [
            'idempotencyKey' => 'sess_free_' . time(),
            'ticketTypeId' => $this->ticketId,
            'quantity' => 1,
            'email' => 'customer_free@test.com',
            'fullName' => 'Jane Free',
            'mobile' => '01000000002',
            'countryCode' => 'EG',
            'countryName' => 'Egypt',
        ];

        $response = $this->postJson("/api/public/events/{$this->slug}/checkout", $payload);
        $response->assertStatus(200)
                 ->assertJsonPath('data.checkout.isFree', true)
                 ->assertJsonPath('data.checkout.currency', 'EGP')
                 ->assertJsonPath('data.checkout.price', 0);

        $registrationId = $response->json('data.registration.id');
        $registration = DB::table('registrations')->where('id', $registrationId)->first();

        $this->assertEquals('approved', $registration->registration_status);

        $order = DB::table('orders')->where('id', $registration->order_id)->first();
        $this->assertEquals('paid', $order->status);

        // Verify ticket issuance
        $ticket = DB::table('generated_tickets')->where('registration_id', $registrationId)->first();
        $this->assertNotNull($ticket);

        $attendee = DB::table('attendees')->where('id', $ticket->attendee_id)->first();
        $this->assertNotNull($attendee);
        $this->assertEquals('Jane Free', $attendee->full_name);
    }

    public function test_authenticated_checkout_links_registration_to_account_and_keeps_portal_private()
    {
        $role = DB::table('roles')->where('code', 'customer')->first();
        $this->assertNotNull($role);

        $customer = User::create([
            'role_id' => $role->id,
            'name' => 'Portal Owner',
            'email' => 'portal-owner-' . uniqid() . '@test.com',
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'preferred_language' => 'en',
        ]);
        $otherCustomer = User::create([
            'role_id' => $role->id,
            'name' => 'Portal Other',
            'email' => 'portal-other-' . uniqid() . '@test.com',
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'preferred_language' => 'en',
        ]);

        $otherDoctorId = DB::table('doctors')->insertGetId([
            'user_id' => $otherCustomer->id,
            'full_name' => 'Other Existing Doctor',
            'email' => $otherCustomer->email,
            'mobile' => '01000000009',
            'country_code' => 'US',
        ]);

        $token = app('auth')->guard('api')->createToken($customer);
        $otherToken = app('auth')->guard('api')->createToken($otherCustomer);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/public/events/{$this->slug}/checkout", [
                'idempotencyKey' => 'sess_auth_owner_' . uniqid(),
                'ticketTypeId' => $this->ticketId,
                'quantity' => 1,
                'email' => $customer->email,
                'fullName' => 'Portal Owner Registration',
                'mobile' => '01000000010',
                'countryCode' => 'US',
                'countryName' => 'United States',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $registrationId = $response->json('data.registration.id');
        $registration = DB::table('registrations')->where('id', $registrationId)->first();
        $doctor = DB::table('doctors')->where('id', $registration->doctor_id)->first();
        $order = DB::table('orders')->where('id', $registration->order_id)->first();

        $this->assertEquals($customer->id, $doctor->user_id);
        $this->assertEquals($customer->id, $order->customer_id);
        $this->assertNotEquals($otherDoctorId, $doctor->id);
        $this->assertDatabaseHas('doctors', [
            'user_id' => $otherCustomer->id,
            'full_name' => 'Other Existing Doctor',
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/me/registrations')
            ->assertStatus(200)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.data.0.id', $registrationId)
            ->assertJsonPath('data.data.0.event_title_en', 'Test Checkout Event')
            ->assertJsonPath('data.data.0.registration_status', 'pending_payment')
            ->assertJsonPath('data.data.0.payment_status', 'pending');

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("/api/me/registrations/{$registrationId}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $registrationId);

        $otherRegistrations = $this->withHeaders(['Authorization' => "Bearer {$otherToken}"])
            ->getJson('/api/me/registrations')
            ->assertStatus(200)
            ->json('data.data');
        $this->assertFalse(collect($otherRegistrations)->contains(fn ($row) => (int) $row['id'] === (int) $registrationId));

        $this->withHeaders(['Authorization' => "Bearer {$otherToken}"])
            ->getJson("/api/me/registrations/{$registrationId}")
            ->assertStatus(404);
    }

    public function test_registration_lookup()
    {
        // Create registration
        $payload = [
            'idempotencyKey' => 'sess_lookup_' . time(),
            'ticketTypeId' => $this->ticketId,
            'quantity' => 1,
            'email' => 'lookup@test.com',
            'fullName' => 'Lookup User',
            'mobile' => '01000000003',
            'countryCode' => 'EG',
            'countryName' => 'Egypt',
        ];

        $response = $this->postJson("/api/public/events/{$this->slug}/checkout", $payload);
        $ref = $response->json('data.registration.registration_number');
        $token = $response->json('data.confirmationToken');
        $this->assertNotNull($ref);
        $this->assertNotNull($token);

        $lookupRes = $this->getJson("/api/public/events/registrations/{$ref}");
        $lookupRes->assertStatus(403);

        $lookupRes = $this->getJson("/api/public/events/registrations/{$ref}?token={$token}");
        $lookupRes->assertStatus(200)
                  ->assertCookie("se_conf_{$ref}")
                  ->assertJsonPath('data.registration.registration_number', $ref)
                  ->assertJsonPath('data.registration.customerName', 'Lookup User')
                  ->assertJsonPath('data.registration.ticket_name_en', 'Standard Checkout Ticket')
                  ->assertJsonPath('data.registration.event_slug', $this->slug)
                  ->assertJsonPath('data.registrationNumber', $ref)
                  ->assertJsonPath('data.customerName', 'Lookup User')
                  ->assertJsonPath('data.ticket.nameEn', 'Standard Checkout Ticket')
                  ->assertJsonPath('data.event.slug', $this->slug);
    }
}
