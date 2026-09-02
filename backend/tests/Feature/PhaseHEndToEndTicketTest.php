<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Event;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

class PhaseHEndToEndTicketTest extends TestCase
{
    use DatabaseTransactions;

    protected $admin;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $roleIdAdmin = DB::table('roles')->where('code', 'admin')->value('id');
        if (!$roleIdAdmin) {
            $roleIdAdmin = DB::table('roles')->insertGetId([
                'code' => 'admin',
                'name_en' => 'Admin',
                'name_ar' => 'مشرف',
                'description' => 'Administrator'
            ]);
        }
        
        $adminId = DB::table('users')->insertGetId([
            'role_id' => $roleIdAdmin,
            'name' => 'Admin User',
            'email' => 'admin.test.phaseh@test.com',
            'password_hash' => 'hash',
            'status' => 'active'
        ]);
        $this->admin = User::find($adminId);
        
        $permissions = ['events.manage', 'tickets.manage', 'checkin.manage', 'pricing.manage'];
        foreach ($permissions as $perm) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleIdAdmin, 'permission_key' => $perm],
                ['allowed' => 1, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function test_end_to_end_ticket_lifecycle()
    {
        // 1. Create Event WITHOUT any ticket/pricing
        $eventData = [
            'titleEn' => 'E2E Test Event',
            'slug' => 'e2e-test-event-' . Str::random(6),
            'startsAt' => Carbon::now()->addDays(10)->toIso8601String(),
            'endsAt' => Carbon::now()->addDays(12)->toIso8601String(),
            'status' => 'published',
            'type' => 'conference',
            'maxTicketsPerCheckout' => 4,
        ];
        
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/events', $eventData);
            
        $response->assertStatus(200);
        $eventId = $response->json('data.id');
        
        // Ensure no tickets created automatically
        $this->assertDatabaseMissing('ticket_types', ['event_id' => $eventId]);

        // 2 & 3. Add Multiple Ticket Types with Pricing Periods
        $ticketGeneral = [
            'eventId' => $eventId,
            'nameEn' => 'General',
            'nameAr' => 'عام',
            'code' => 'GEN',
            'visibility' => 'public',
            'quota' => 100,
            'maxPerOrder' => 4,
            'isActive' => true
        ];
        
        $ticketVIP = [
            'eventId' => $eventId,
            'nameEn' => 'VIP',
            'nameAr' => 'VIP',
            'code' => 'VIP',
            'visibility' => 'public',
            'quota' => 10,
            'maxPerOrder' => 2,
            'isActive' => true
        ];

        $resGen = $this->actingAs($this->admin, 'api')
            ->postJson("/api/tickets", $ticketGeneral);
        $resGen->assertStatus(200);
        $genTicketId = $resGen->json('data.id');
            
        $resVip = $this->actingAs($this->admin, 'api')
            ->postJson("/api/tickets", $ticketVIP);
        $resVip->assertStatus(200);
        $vipTicketId = $resVip->json('data.id');

        // Price Periods
        $this->postJson("/api/tickets/price-periods", [
            'ticketTypeId' => $genTicketId,
            'labelEn' => 'Early Bird',
            'labelAr' => 'Early Bird',
            'priceEgp' => 0,
            'startsAt' => Carbon::now()->subDays(1)->toIso8601String(),
            'endsAt' => Carbon::now()->addDays(5)->toIso8601String(),
            'isActive' => true
        ])->assertStatus(200);

        $this->postJson("/api/tickets/price-periods", [
            'ticketTypeId' => $genTicketId,
            'labelEn' => 'Regular',
            'labelAr' => 'Regular',
            'priceEgp' => 100,
            'startsAt' => Carbon::now()->addDays(6)->toIso8601String(),
            'endsAt' => Carbon::now()->addDays(10)->toIso8601String(),
            'isActive' => true
        ])->assertStatus(200);
        
        $this->postJson("/api/tickets/price-periods", [
            'ticketTypeId' => $vipTicketId,
            'labelEn' => 'Standard VIP',
            'labelAr' => 'Standard VIP',
            'priceEgp' => 200,
            'startsAt' => Carbon::now()->subDays(1)->toIso8601String(),
            'endsAt' => Carbon::now()->addDays(10)->toIso8601String(),
            'isActive' => true
        ])->assertStatus(200);

        // 4. Public Booking Display
        $publicRes = $this->getJson("/api/public/events/" . $eventData['slug']);
        $publicRes->assertStatus(200);
        
        $tickets = $publicRes->json('data.tickets');
        $this->assertCount(2, $tickets);
        
        $genTicket = collect($tickets)->firstWhere('name_en', 'General');
        $this->assertNotNull($genTicket);
        $this->assertEquals(0, (int)$genTicket['price']); // Early bird is active
        
        // 5. Checkout Enforcements
        // Over Max per order
        $checkoutFail = $this->postJson("/api/public/events/{$eventData['slug']}/checkout", [
            'idempotencyKey' => Str::random(16),
            'ticketTypeId' => $genTicket['id'],
            'quantity' => 5,
            'fullName' => 'Test User',
            'email' => 'test@test.com',
            'mobile' => '1234',
            'countryCode' => 'EG',
            'countryName' => 'Egypt'
        ]);
        if ($checkoutFail->status() !== 400 && $checkoutFail->status() !== 422) {
             dd($checkoutFail->json());
        }
        $this->assertContains($checkoutFail->status(), [400, 422]); // Validation should fail
        
        // Successful checkout
        $checkoutSuccess = $this->postJson("/api/public/events/{$eventData['slug']}/checkout", [
            'idempotencyKey' => Str::random(16),
            'ticketTypeId' => $genTicket['id'],
            'quantity' => 2,
            'fullName' => 'John Doe',
            'email' => 'john@test.com',
            'mobile' => '123456789',
            'countryCode' => 'EG',
            'countryName' => 'Egypt'
        ]);
        if ($checkoutSuccess->status() !== 200) dd($checkoutSuccess->json());
        $checkoutSuccess->assertStatus(200);
        $registrationId = $checkoutSuccess->json('data.registration.id');
        
        // 6 & 7. QR Check-in
        // Get the attendees for this registration
        $attendeeId = \Illuminate\Support\Facades\DB::table('generated_tickets')
            ->where('registration_id', $registrationId)
            ->value('attendee_id');
            
        $attendee = \Illuminate\Support\Facades\DB::table('attendees')
            ->where('id', $attendeeId)
            ->first();
            
        $this->assertNotNull($attendee);
        
        $qrToken = $attendee->qr_token;
        
        // Scan Valid QR
        $checkinSuccess = $this->actingAs($this->admin, 'api')
            ->postJson("/api/attendees/checkin", [
                'eventId' => $eventId,
                'qrToken' => $qrToken
            ]);
        if ($checkinSuccess->status() !== 200) dd($checkinSuccess->json());
        $checkinSuccess->assertStatus(200)
            ->assertJson(['success' => true]);
            
        // Validate Check-in Recorded
        $checkedAttendee = \Illuminate\Support\Facades\DB::table('attendees')
            ->where('id', $attendeeId)
            ->first();
        $this->assertNotNull($checkedAttendee->checked_in_at);
        
        // Duplicate Scan
        $checkinDup = $this->actingAs($this->admin, 'api')
            ->postJson("/api/attendees/checkin", [
                'eventId' => $eventId,
                'qrToken' => $qrToken
            ]);
        $checkinDup->assertStatus(409); // Already checked in
        
        // Invalid Token
        $checkinInv = $this->actingAs($this->admin, 'api')
            ->postJson("/api/attendees/checkin", [
                'eventId' => $eventId,
                'qrToken' => 'invalid-token'
            ]);
        $checkinInv->assertStatus(404); // Not found      
        // 8. Re-check state after check-in
        $this->assertDatabaseHas('attendees', [
            'id' => $attendeeId,
            'qr_status' => 'used'
        ]);
    }
}
