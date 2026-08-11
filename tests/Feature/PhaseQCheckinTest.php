<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseQCheckinTest extends TestCase
{
    use DatabaseTransactions;

    private function roleId(string $code): int
    {
        $role = DB::table('roles')->where('code', $code)->first();
        if ($role) return (int)$role->id;

        return DB::table('roles')->insertGetId([
            'code' => $code,
            'name_en' => ucfirst($code),
            'name_ar' => ucfirst($code),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function allow(int $roleId, string $permission): void
    {
        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $roleId, 'permission_key' => $permission],
            ['allowed' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function user(string $roleCode, string $email): User
    {
        $roleId = $this->roleId($roleCode);
        $id = DB::table('users')->insertGetId([
            'name' => $roleCode . ' user',
            'email' => $email,
            'password_hash' => 'hash',
            'status' => 'active',
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::find($id);
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer ' . app('auth')->guard('api')->createToken($user)];
    }

    private function event(string $slug): int
    {
        return DB::table('events')->insertGetId([
            'slug' => $slug,
            'title_en' => ucfirst(str_replace('-', ' ', $slug)),
            'title_ar' => $slug,
            'type' => 'conference',
            'status' => 'published',
            'public_registration_enabled' => 1,
            'registration_approval_mode' => 'automatic',
            'registration_access' => 'guest_allowed',
            'max_tickets_per_checkout' => 1,
            'max_attendees' => 100,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(3),
            'registration_starts_at' => now()->subDay(),
            'registration_ends_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ticketType(int $eventId): int
    {
        $ticketTypeId = DB::table('ticket_types')->insertGetId([
            'event_id' => $eventId,
            'name_en' => 'General Admission',
            'name_ar' => 'General Admission',
            'is_active' => 1,
            'quota' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ticket_price_periods')->insert([
            'ticket_type_id' => $ticketTypeId,
            'label_en' => 'Free',
            'label_ar' => 'Free',
            'price' => 0,
            'price_egp' => 0,
            'price_usd' => 0,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(10),
            'is_active' => 1,
        ]);

        return $ticketTypeId;
    }

    private function attendeeTicket(int $eventId, string $token, string $status = 'active'): array
    {
        $ticketTypeId = $this->ticketType($eventId);
        $customer = $this->user('customer', 'owner-' . uniqid() . '@test.com');
        $doctorId = DB::table('doctors')->insertGetId([
            'user_id' => $customer->id,
            'full_name' => 'Ticket Owner',
            'email' => $customer->email,
            'mobile' => '01000000000',
            'country_code' => 'EG',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-Q-' . uniqid(),
            'event_id' => $eventId,
            'customer_name' => 'Ticket Owner',
            'customer_email' => $customer->email,
            'customer_phone' => '01000000000',
            'subtotal' => 0,
            'grand_total' => 0,
            'currency' => 'EGP',
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $registrationId = DB::table('registrations')->insertGetId([
            'registration_number' => 'REG-Q-' . uniqid(),
            'doctor_id' => $doctorId,
            'event_id' => $eventId,
            'ticket_type_id' => $ticketTypeId,
            'order_id' => $orderId,
            'source' => 'online',
            'registration_status' => 'approved',
            'payment_status' => 'free',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attendeeId = DB::table('attendees')->insertGetId([
            'order_id' => $orderId,
            'event_id' => $eventId,
            'ticket_type_id' => $ticketTypeId,
            'attendee_number' => 'ATT-Q-' . uniqid(),
            'full_name' => 'Ticket Owner',
            'email' => $customer->email,
            'phone' => '01000000000',
            'qr_token' => $token,
            'qr_status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ticketId = DB::table('generated_tickets')->insertGetId([
            'registration_id' => $registrationId,
            'attendee_id' => $attendeeId,
            'ticket_number' => 'TKT-Q-' . uniqid(),
            'qr_token' => $token,
            'generated_at' => now(),
            'created_at' => now(),
        ]);

        return compact('customer', 'doctorId', 'orderId', 'registrationId', 'attendeeId', 'ticketId', 'ticketTypeId');
    }

    public function test_public_free_checkout_generates_unique_opaque_tokens_and_is_idempotent(): void
    {
        $eventId = $this->event('phase-q-free-checkout-' . uniqid());
        $ticketTypeId = $this->ticketType($eventId);
        $slug = DB::table('events')->where('id', $eventId)->value('slug');

        $payload = [
            'idempotencyKey' => 'phase-q-idem-' . uniqid(),
            'ticketTypeId' => $ticketTypeId,
            'quantity' => 1,
            'email' => 'private-one@example.test',
            'fullName' => 'Private One',
            'mobile' => '01000000001',
            'countryCode' => 'EG',
            'countryName' => 'Egypt',
        ];

        $first = $this->postJson("/api/public/events/{$slug}/checkout", $payload);
        $first->assertStatus(200)->assertJsonPath('data.checkout.isFree', true);
        $firstRegistrationId = $first->json('data.registration.id');

        $repeat = $this->postJson("/api/public/events/{$slug}/checkout", $payload);
        $repeat->assertStatus(200)->assertJsonPath('data.repeated', true);
        $this->assertEquals($firstRegistrationId, $repeat->json('data.registration.id'));
        $this->assertEquals(1, DB::table('generated_tickets')->where('registration_id', $firstRegistrationId)->count());

        $secondPayload = array_merge($payload, [
            'idempotencyKey' => 'phase-q-idem-' . uniqid(),
            'email' => 'private-two@example.test',
            'fullName' => 'Private Two',
            'mobile' => '01000000002',
        ]);
        $second = $this->postJson("/api/public/events/{$slug}/checkout", $secondPayload);
        $second->assertStatus(200);

        $tokens = DB::table('generated_tickets')
            ->join('registrations', 'registrations.id', '=', 'generated_tickets.registration_id')
            ->where('registrations.event_id', $eventId)
            ->pluck('generated_tickets.qr_token')
            ->all();

        $this->assertCount(2, $tokens);
        $this->assertNotEquals($tokens[0], $tokens[1]);
        foreach ($tokens as $token) {
            $this->assertMatchesRegularExpression('/^[A-Fa-f0-9]{64}$/', $token);
            $this->assertStringNotContainsString('private-', $token);
            $this->assertStringNotContainsString('Private', $token);
        }
    }

    public function test_checkin_lifecycle_and_logs(): void
    {
        $adminRole = $this->roleId('admin');
        $this->allow($adminRole, 'checkin.manage');
        $admin = $this->user('admin', 'admin-phase-q@example.test');
        $eventId = $this->event('phase-q-checkin-' . uniqid());
        $token = str_repeat('a', 64);
        $ticket = $this->attendeeTicket($eventId, $token);

        $accepted = $this->withHeaders($this->bearer($admin))->postJson('/api/attendees/checkin', ['qrToken' => $token, 'eventId' => $eventId]);
        $accepted->assertStatus(200)->assertJsonPath('success', true);
        $attendee = DB::table('attendees')->where('id', $ticket['attendeeId'])->first();
        $this->assertEquals('used', $attendee->qr_status);
        $this->assertNotNull($attendee->checked_in_at);
        $checkedInAt = $attendee->checked_in_at;

        $duplicate = $this->withHeaders($this->bearer($admin))->postJson('/api/attendees/checkin', ['qrToken' => $token, 'eventId' => $eventId]);
        $duplicate->assertStatus(409)->assertJsonPath('details.result', 'duplicate');
        $this->assertEquals($checkedInAt, DB::table('attendees')->where('id', $ticket['attendeeId'])->value('checked_in_at'));
        $this->assertEquals(1, DB::table('checkin_logs')->where('attendee_id', $ticket['attendeeId'])->where('scan_result', 'accepted')->count());
        $this->assertEquals(1, DB::table('checkin_logs')->where('attendee_id', $ticket['attendeeId'])->where('scan_result', 'duplicate')->count());
        $this->assertTrue(DB::table('checkin_logs')->where('attendee_id', $ticket['attendeeId'])->where('scanned_by_user_id', $admin->id)->whereNotNull('scanned_at')->exists());
    }

    public function test_invalid_revoked_and_wrong_event_scans_are_rejected(): void
    {
        $adminRole = $this->roleId('admin');
        $this->allow($adminRole, 'checkin.manage');
        $admin = $this->user('admin', 'admin-phase-q-errors@example.test');
        $eventA = $this->event('phase-q-event-a-' . uniqid());
        $eventB = $this->event('phase-q-event-b-' . uniqid());
        $revoked = $this->attendeeTicket($eventA, str_repeat('b', 64), 'revoked');
        $wrongEvent = $this->attendeeTicket($eventA, str_repeat('c', 64));

        $this->withHeaders($this->bearer($admin))->postJson('/api/attendees/checkin', ['qrToken' => 'not-a-real-qr'])
            ->assertStatus(404)->assertJsonPath('details.result', 'invalid');

        $this->withHeaders($this->bearer($admin))->postJson('/api/attendees/checkin', ['qrToken' => str_repeat('d', 64)])
            ->assertStatus(404)->assertJsonPath('details.result', 'invalid');

        $this->withHeaders($this->bearer($admin))->postJson('/api/attendees/checkin', ['qrToken' => str_repeat('b', 64), 'eventId' => $eventA])
            ->assertStatus(409)->assertJsonPath('details.result', 'revoked');
        $this->assertNull(DB::table('attendees')->where('id', $revoked['attendeeId'])->value('checked_in_at'));

        $this->withHeaders($this->bearer($admin))->postJson('/api/attendees/checkin', ['qrToken' => str_repeat('c', 64), 'eventId' => $eventB])
            ->assertStatus(409)->assertJsonPath('details.result', 'wrong_event');
        $this->assertNull(DB::table('attendees')->where('id', $wrongEvent['attendeeId'])->value('checked_in_at'));
        $this->assertTrue(DB::table('checkin_logs')->where('attendee_id', $wrongEvent['attendeeId'])->where('scan_result', 'invalid')->where('notes', 'like', 'wrong_event:%')->exists());
    }

    public function test_event_staff_scope_and_customer_ownership_are_enforced(): void
    {
        $employeeRole = $this->roleId('employee');
        $this->allow($employeeRole, 'checkin.manage');
        $employee = $this->user('employee', 'employee-phase-q@example.test');
        $customer = $this->user('customer', 'customer-phase-q@example.test');
        $eventA = $this->event('phase-q-scope-a-' . uniqid());
        $eventB = $this->event('phase-q-scope-b-' . uniqid());
        DB::table('event_staff_assignments')->insert([
            'event_id' => $eventA,
            'user_id' => $employee->id,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketA = $this->attendeeTicket($eventA, str_repeat('e', 64));
        $ticketB = $this->attendeeTicket($eventB, str_repeat('f', 64));

        $this->withHeaders($this->bearer($employee))->postJson('/api/attendees/checkin', ['qrToken' => str_repeat('e', 64), 'eventId' => $eventA])
            ->assertStatus(200);

        $this->withHeaders($this->bearer($employee))->postJson('/api/attendees/checkin', ['qrToken' => str_repeat('f', 64), 'eventId' => $eventB])
            ->assertStatus(403);

        $this->withHeaders($this->bearer($customer))->postJson('/api/attendees/checkin', ['qrToken' => str_repeat('f', 64), 'eventId' => $eventB])
            ->assertStatus(403);

        app('auth')->forgetGuards();
        $this->flushHeaders();
        $ownerHeaders = $this->bearer($ticketB['customer']);
        $this->withHeaders($ownerHeaders)->getJson('/api/me/tickets/' . $ticketB['ticketId'] . '/qr')
            ->assertStatus(200)
            ->assertJsonPath('data.qrPayload', str_repeat('f', 64));

        $otherCustomer = $this->user('customer', 'other-customer-phase-q@example.test');
        app('auth')->forgetGuards();
        $this->flushHeaders();
        $this->withHeaders($this->bearer($otherCustomer))->getJson('/api/me/tickets/' . $ticketB['ticketId'] . '/qr')
            ->assertStatus(404);

        app('auth')->forgetGuards();
        $this->flushHeaders();
        $this->withHeaders($this->bearer($ticketA['customer']))->getJson('/api/me/tickets/' . $ticketA['ticketId'] . '/qr')
            ->assertStatus(409);
    }
}
