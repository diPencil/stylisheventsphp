<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChairmanSpeakerRoleTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('roles:sync-defaults')->assertExitCode(0);

        $adminRole = DB::table('roles')->where('code', 'admin')->first();
        $this->admin = User::create([
            'role_id' => $adminRole->id,
            'name' => 'Role Admin',
            'email' => 'role-admin-' . uniqid() . '@test.com',
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'preferred_language' => 'en',
        ]);

        foreach (['users.manage', 'roles.manage'] as $permission) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $adminRole->id, 'permission_key' => $permission],
                ['allowed' => 1]
            );
        }

        $this->adminToken = 'Bearer ' . Auth::guard('api')->createToken($this->admin);
    }

    public function test_roles_are_listed_with_participant_permissions_only(): void
    {
        $this->withHeaders(['Authorization' => $this->adminToken])
            ->getJson('/api/users/roles')
            ->assertStatus(200)
            ->assertJsonPath('data.roles.4.code', 'chairman')
            ->assertJsonPath('data.roles.4.nameEn', 'Chairman')
            ->assertJsonPath('data.roles.4.nameAr', 'رئيس الجلسة')
            ->assertJsonPath('data.roles.5.code', 'speaker')
            ->assertJsonPath('data.roles.5.nameEn', 'Speaker')
            ->assertJsonPath('data.roles.5.nameAr', 'متحدث');

        foreach (['chairman', 'speaker'] as $code) {
            $roleId = DB::table('roles')->where('code', $code)->value('id');
            $this->assertDatabaseHas('role_permissions', [
                'role_id' => $roleId,
                'permission_key' => 'profile.manage',
                'allowed' => 1,
            ]);
            foreach (['users.manage', 'roles.manage', 'events.manage', 'settings.manage'] as $permission) {
                $this->assertFalse(DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_key', $permission)
                    ->where('allowed', 1)
                    ->exists());
            }
        }
    }

    public function test_admin_can_create_and_edit_chairman_and_speaker_users(): void
    {
        $chairman = $this->withHeaders(['Authorization' => $this->adminToken])
            ->postJson('/api/users', [
                'name' => 'QA Chairman',
                'email' => 'qa-chairman-' . uniqid() . '@test.com',
                'password' => 'password123',
                'roleCode' => 'chairman',
                'status' => 'active',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.role.code', 'chairman')
            ->json('data');

        $speaker = $this->withHeaders(['Authorization' => $this->adminToken])
            ->postJson('/api/users', [
                'name' => 'QA Speaker',
                'email' => 'qa-speaker-' . uniqid() . '@test.com',
                'password' => 'password123',
                'roleCode' => 'speaker',
                'status' => 'active',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.role.code', 'speaker')
            ->json('data');

        $this->withHeaders(['Authorization' => $this->adminToken])
            ->putJson('/api/users/' . $speaker['id'], ['roleCode' => 'chairman'])
            ->assertStatus(200)
            ->assertJsonPath('data.role.code', 'chairman');

        $this->withHeaders(['Authorization' => $this->adminToken])
            ->putJson('/api/users/' . $chairman['id'], ['roleCode' => 'speaker'])
            ->assertStatus(200)
            ->assertJsonPath('data.role.code', 'speaker');
    }

    public function test_chairman_and_speaker_can_use_owned_participant_portal_data(): void
    {
        foreach (['chairman', 'speaker'] as $roleCode) {
            $user = $this->createParticipantWithLifecycle($roleCode);
            $token = 'Bearer ' . Auth::guard('api')->createToken($user);

            Auth::guard('api')->forgetUser();
            $this->withHeaders(['Authorization' => $token])
                ->getJson('/api/auth/me')
                ->assertStatus(200)
                ->assertJsonPath('data.role_code', $roleCode);

            Auth::guard('api')->forgetUser();
            $this->withHeaders(['Authorization' => $token])
                ->getJson('/api/me/dashboard')
                ->assertStatus(200)
                ->assertJsonPath('data.summary.totalRegistrations', 1)
                ->assertJsonPath('data.summary.activeTickets', 1)
                ->assertJsonPath('data.summary.availableCertificates', 1);

            Auth::guard('api')->forgetUser();
            $this->withHeaders(['Authorization' => $token])
                ->getJson('/api/me/registrations')
                ->assertStatus(200)
                ->assertJsonPath('data.pagination.total', 1);

            Auth::guard('api')->forgetUser();
            $this->withHeaders(['Authorization' => $token])
                ->getJson('/api/me/tickets')
                ->assertStatus(200)
                ->assertJsonPath('data.pagination.total', 1);

            Auth::guard('api')->forgetUser();
            $this->withHeaders(['Authorization' => $token])
                ->getJson('/api/me/certificates')
                ->assertStatus(200)
                ->assertJsonPath('data.pagination.total', 1);
        }
    }

    public function test_chairman_and_speaker_are_blocked_from_admin_apis_by_default(): void
    {
        foreach (['chairman', 'speaker'] as $roleCode) {
            $user = $this->createParticipantWithLifecycle($roleCode);
            $token = 'Bearer ' . Auth::guard('api')->createToken($user);

            Auth::guard('api')->forgetUser();
            $this->withHeaders(['Authorization' => $token])->getJson('/api/users')->assertStatus(403);
            Auth::guard('api')->forgetUser();
            $this->withHeaders(['Authorization' => $token])->getJson('/api/users/roles')->assertStatus(403);
            Auth::guard('api')->forgetUser();
            $this->withHeaders(['Authorization' => $token])
                ->postJson('/api/events', ['titleEn' => 'Denied Event'])
                ->assertStatus(403);
        }
    }

    private function createParticipantWithLifecycle(string $roleCode): User
    {
        $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
        $email = 'qa-' . $roleCode . '-' . uniqid() . '@test.com';

        $user = User::create([
            'role_id' => $roleId,
            'name' => 'QA ' . ucfirst($roleCode),
            'email' => $email,
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'preferred_language' => 'en',
        ]);

        $doctorId = DB::table('doctors')->insertGetId([
            'user_id' => $user->id,
            'full_name' => $user->name,
            'email' => $email,
            'mobile' => '01000000000',
            'country_code' => 'EG',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventId = DB::table('events')->insertGetId([
            'title_en' => 'QA Participant Role Event',
            'slug' => 'qa-participant-role-' . uniqid(),
            'type' => 'conference',
            'status' => 'published',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'timezone' => 'Africa/Cairo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketTypeId = DB::table('ticket_types')->insertGetId([
            'event_id' => $eventId,
            'name_en' => 'Participant Ticket',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-ROLE-' . strtoupper(uniqid()),
            'customer_id' => $user->id,
            'event_id' => $eventId,
            'customer_name' => $user->name,
            'customer_email' => $email,
            'subtotal' => 0,
            'grand_total' => 0,
            'currency' => 'EGP',
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $registrationId = DB::table('registrations')->insertGetId([
            'registration_number' => 'REG-ROLE-' . strtoupper(uniqid()),
            'doctor_id' => $doctorId,
            'event_id' => $eventId,
            'ticket_type_id' => $ticketTypeId,
            'order_id' => $orderId,
            'source' => 'manual',
            'registration_status' => 'approved',
            'payment_status' => 'free',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attendeeId = DB::table('attendees')->insertGetId([
            'event_id' => $eventId,
            'ticket_type_id' => $ticketTypeId,
            'order_id' => $orderId,
            'attendee_number' => 'ATT-ROLE-' . strtoupper(uniqid()),
            'email' => $email,
            'full_name' => $user->name,
            'qr_token' => hash('sha256', $email),
            'qr_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('generated_tickets')->insert([
            'registration_id' => $registrationId,
            'attendee_id' => $attendeeId,
            'ticket_number' => 'TKT-ROLE-' . strtoupper(uniqid()),
            'pdf_url' => '/tickets/role.pdf',
            'qr_token' => hash('sha256', 'ticket-' . $email),
            'generated_at' => now(),
            'created_at' => now(),
        ]);

        DB::table('certificates')->insert([
            'attendee_id' => $attendeeId,
            'certificate_number' => 'CERT-ROLE-' . strtoupper(uniqid()),
            'status' => 'issued',
            'file_url' => '/certificates/role.pdf',
            'issued_at' => now(),
            'created_at' => now(),
        ]);

        return $user;
    }
}
