<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class PhaseERegistrationTest extends TestCase
{
    use DatabaseTransactions;

    private $eventId;
    private $ticketId;
    private $adminToken;
    private $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventId = DB::table('events')->insertGetId([
            'slug' => 'phase-e-event',
            'title_en' => 'Phase E Event',
            'title_ar' => 'Phase E Event AR',
            'status' => 'published',
            'public_registration_enabled' => 1,
            'registration_approval_mode' => 'manual_review',
            'max_tickets_per_checkout' => 4,
            'max_attendees' => 10,
            'capacity_hold_hours_override' => 24,
            'manual_payment_enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ticketId = DB::table('ticket_types')->insertGetId([
            'event_id' => $this->eventId,
            'name_en' => 'Phase E Ticket',
            'name_ar' => 'Phase E Ticket AR',
            'is_active' => 1,
            'quota' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ticket_price_periods')->insert([
            'ticket_type_id' => $this->ticketId,
            'price' => 100,
            'price_egp' => 1500,
            'price_usd' => 100,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
            'is_active' => 1,
        ]);

        $role = DB::table('roles')->where('code', 'admin')->first();
        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'permission_key' => 'registrations.manage'],
            ['allowed' => 1]
        );
        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'permission_key' => 'registrations.create_manual'],
            ['allowed' => 1]
        );
        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'permission_key' => 'payments.verify'],
            ['allowed' => 1]
        );
        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'permission_key' => 'attendees.manage'],
            ['allowed' => 1]
        );
        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'permission_key' => 'checkin.manage'],
            ['allowed' => 1]
        );

        $this->adminId = DB::table('users')->insertGetId([
            'name' => 'Admin User',
            'email' => 'admin_phase_e@test.com',
            'password_hash' => 'hash',
            'status' => 'active',
            'role_id' => $role->id,
        ]);

        $user = User::find($this->adminId);
        $this->adminToken = 'Bearer ' . app('auth')->guard('api')->createToken($user);
    }

    public function test_registration_list_forbidden()
    {
        $response = $this->getJson('/api/registrations');
        $response->assertStatus(401);
    }

    public function test_create_registration_and_list()
    {
        $payload = [
            'eventId' => $this->eventId,
            'ticketTypeId' => $this->ticketId,
            'fullName' => 'Test User',
            'email' => 'test@test.com',
            'mobile' => '01011111111',
            'countryCode' => 'EG',
            'countryName' => 'Egypt',
            'city' => 'Cairo',
            'specialty' => 'Dev',
            'nationality' => 'Egyptian'
        ];

        $response = $this->postJson('/api/registrations', $payload);
        $response->assertStatus(200)->assertJsonPath('success', true);
        $regId = $response->json('data.id');
        $this->assertNotNull($regId);

        $listResponse = $this->getJson('/api/registrations', ['Authorization' => $this->adminToken]);
        $listResponse->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($listResponse->json('data')));
        $this->assertDatabaseHas('orders', ['id' => DB::table('registrations')->where('id', $regId)->value('order_id')]);

        // 1. Submit proof
        $proofRes = $this->patchJson("/api/registrations/{$regId}/payment-proof", [
            'paymentProofUrl' => 'https://test.com/proof.jpg'
        ]);
        $proofRes->assertStatus(200);

        // 2. Review Payment as approved
        $reviewRes = $this->patchJson("/api/registrations/{$regId}/payment-review", [
            'status' => 'approved'
        ], ['Authorization' => $this->adminToken]);

        $reviewRes->assertStatus(200)->assertJsonPath('data.status', 'pending_review');

        // 3. Review Registration as approved
        $approveRes = $this->patchJson("/api/registrations/{$regId}/review", [
            'status' => 'approved'
        ], ['Authorization' => $this->adminToken]);

        $approveRes->assertStatus(200)->assertJsonPath('data.status', 'approved');
        $attendeeId = $approveRes->json('data.attendeeId');
        $this->assertNotNull($attendeeId);

        // 4. Attendee list and checkin
        $attendeeRes = $this->getJson("/api/attendees/{$attendeeId}", ['Authorization' => $this->adminToken]);
        $attendeeRes->assertStatus(200);
        $qrToken = $attendeeRes->json('data.qr_token');
        $this->assertNotNull($qrToken);

        $checkinRes = $this->postJson("/api/attendees/checkin", [
            'qrToken' => $qrToken
        ], ['Authorization' => $this->adminToken]);

        $checkinRes->assertStatus(200)->assertJsonPath('success', true);

        // Duplicate checkin should fail
        $duplicateRes = $this->postJson("/api/attendees/checkin", [
            'qrToken' => $qrToken
        ], ['Authorization' => $this->adminToken]);

        $duplicateRes->assertStatus(409)->assertJsonPath('details.result', 'duplicate');
    }

    public function test_manual_paid_registration_respects_manual_review_policy()
    {
        $payload = [
            'eventId' => $this->eventId,
            'ticketTypeId' => $this->ticketId,
            'fullName' => 'Manual Review User',
            'email' => 'manual-review@test.com',
            'mobile' => '01022222222',
            'countryCode' => 'EG',
            'countryName' => 'Egypt',
            'city' => 'Cairo',
            'specialty' => 'Cardiology',
            'nationality' => 'Egyptian',
            'preferredLanguage' => 'en',
            'paymentStatus' => 'paid',
            'paymentReference' => 'BANK-MANUAL-PAID',
            'sendEmail' => false,
        ];

        $response = $this->postJson('/api/registrations/manual', $payload, ['Authorization' => $this->adminToken]);
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending_review');

        $registrationId = $response->json('data.id');
        $this->assertNotNull($registrationId);

        $registration = DB::table('registrations')->where('id', $registrationId)->first();
        $this->assertEquals('manual', $registration->source);
        $this->assertEquals('pending_review', $registration->registration_status);
        $this->assertEquals('approved', $registration->payment_status);
        $this->assertEquals($this->adminId, $registration->created_by_user_id);

        $order = DB::table('orders')->where('id', $registration->order_id)->first();
        $this->assertEquals('paid', $order->status);

        $this->assertDatabaseMissing('generated_tickets', ['registration_id' => $registrationId]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'registrations.manual_create',
            'entity_type' => 'registration',
            'entity_id' => (string) $registrationId,
        ]);
    }
}
