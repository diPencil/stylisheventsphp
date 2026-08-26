<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MedicalSpecialtyNotificationTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(string $roleCode, array $permissions = []): string
    {
        $role = Role::where('code', $roleCode)->first();
        $this->assertNotNull($role, "{$roleCode} role exists");
        foreach ($permissions as $permission) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'permission_key' => $permission],
                ['allowed' => 1]
            );
        }
        $user = User::create([
            'role_id' => $role->id,
            'name' => ucfirst($roleCode) . ' Test',
            'email' => $roleCode . '-' . uniqid() . '@test.local',
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'preferred_language' => 'en',
        ]);
        return Auth::guard('api')->createToken($user);
    }

    private function specialty(string $name): int
    {
        DB::table('specialties')->updateOrInsert(
            ['name_en' => $name],
            ['name_ar' => $name . ' AR', 'is_active' => 1, 'updated_at' => now(), 'created_at' => now()]
        );
        return (int) DB::table('specialties')->where('name_en', $name)->value('id');
    }

    private function doctor(string $name, int $specialtyId): User
    {
        $roleId = DB::table('roles')->where('code', 'doctor')->value('id');
        $user = User::create([
            'role_id' => $roleId,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . uniqid() . '@test.local',
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'preferred_language' => 'en',
        ]);
        $specialty = DB::table('specialties')->find($specialtyId);
        DB::table('doctors')->insert([
            'user_id' => $user->id,
            'full_name' => $name,
            'mobile' => '01000000000',
            'email' => $user->email,
            'country_code' => 'EG',
            'country_name' => 'Egypt',
            'city' => 'Cairo',
            'specialty' => $specialty->name_en,
            'specialty_id' => $specialtyId,
            'nationality' => 'Egyptian',
            'preferred_language' => 'en',
        ]);
        return $user;
    }

    public function test_specialty_crud_requires_settings_permission(): void
    {
        $customerToken = $this->tokenFor('customer');
        $this->withHeaders(['Authorization' => "Bearer {$customerToken}"])
            ->postJson('/api/specialties', ['nameEn' => 'Neurology', 'nameAr' => 'مخ وأعصاب'])
            ->assertStatus(403);

        $adminToken = $this->tokenFor('admin', ['settings.manage']);
        $created = $this->withHeaders(['Authorization' => "Bearer {$adminToken}"])
            ->postJson('/api/specialties', ['nameEn' => 'Neurology ' . uniqid(), 'nameAr' => 'مخ وأعصاب'])
            ->assertStatus(200)
            ->json('data');

        $this->withHeaders(['Authorization' => "Bearer {$adminToken}"])
            ->patchJson('/api/specialties/' . $created['id'] . '/status', ['isActive' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.isActive', false);
    }

    public function test_doctor_signup_requires_active_specialty_and_blocks_privileged_tampering(): void
    {
        $cardiology = $this->specialty('Cardiology');
        $this->postJson('/api/auth/register', [
            'name' => 'Doctor No Specialty',
            'email' => 'nospecialty-' . uniqid() . '@test.local',
            'countryCode' => 'EG',
            'countryName' => 'Egypt',
            'password' => 'password123',
            'accountType' => 'doctor',
        ])->assertStatus(422);

        // Explicit privileged roleCode should be rejected even when accountType=doctor
        $this->postJson('/api/auth/register', [
            'name' => 'Doctor Cardiology',
            'email' => 'doctor-signup-' . uniqid() . '@test.local',
            'countryCode' => 'EG',
            'countryName' => 'Egypt',
            'password' => 'password123',
            'roleCode' => 'admin',
            'accountType' => 'doctor',
            'specialtyId' => $cardiology,
        ])->assertStatus(400);
    }

    public function test_event_matching_all_specialties_dedupe_and_notification_privacy(): void
    {
        $adminToken = $this->tokenFor('admin', ['events.manage']);
        $cardiology = $this->specialty('Cardiology');
        $dentistry = $this->specialty('Dentistry');
        $doctorA = $this->doctor('Doctor A', $cardiology);
        $doctorB = $this->doctor('Doctor B', $dentistry);

        $event = $this->withHeaders(['Authorization' => "Bearer {$adminToken}"])
            ->postJson('/api/events', [
                'titleEn' => 'Cardiology Summit',
                'titleAr' => 'Cardiology Summit',
                'startsAt' => now()->addDays(10)->toDateTimeString(),
                'endsAt' => now()->addDays(10)->addHours(2)->toDateTimeString(),
                'googleMapsUrl' => 'https://maps.example/cardio',
                'status' => 'draft',
                'targetAllSpecialties' => false,
                'specialtyIds' => [$cardiology],
            ])->assertStatus(200)->json('data');

        $eventId = $event['id'];
        $this->withHeaders(['Authorization' => "Bearer {$adminToken}"])
            ->patchJson("/api/events/{$eventId}/status", ['status' => 'published'])
            ->assertStatus(200);

        $this->assertDatabaseHas('user_notifications', ['user_id' => $doctorA->id, 'entity_type' => 'event', 'entity_id' => $eventId]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $doctorB->id, 'entity_type' => 'event', 'entity_id' => $eventId]);

        $this->withHeaders(['Authorization' => "Bearer {$adminToken}"])
            ->putJson("/api/events/{$eventId}", [
                'titleEn' => 'Cardiology Summit Updated',
                'titleAr' => 'Cardiology Summit Updated',
                'startsAt' => now()->addDays(10)->toDateTimeString(),
                'endsAt' => now()->addDays(10)->addHours(2)->toDateTimeString(),
                'googleMapsUrl' => 'https://maps.example/cardio',
                'status' => 'published',
                'targetAllSpecialties' => false,
                'specialtyIds' => [$cardiology],
            ])->assertStatus(200);

        $this->assertSame(1, DB::table('user_notifications')->where('user_id', $doctorA->id)->where('entity_id', $eventId)->count());

        $doctorAToken = Auth::guard('api')->createToken($doctorA);
        $doctorBToken = Auth::guard('api')->createToken($doctorB);
        $this->withHeaders(['Authorization' => "Bearer {$doctorAToken}"])->getJson('/api/me/events-for-you')->assertStatus(200)->assertJsonCount(1, 'data.data');
        $this->withHeaders(['Authorization' => "Bearer {$doctorBToken}"])->getJson('/api/me/events-for-you')->assertStatus(200)->assertJsonCount(0, 'data.data');

        $notificationId = DB::table('user_notifications')->where('user_id', $doctorA->id)->value('id');
        $this->withHeaders(['Authorization' => "Bearer {$doctorBToken}"])->patchJson("/api/me/notifications/{$notificationId}/read")->assertStatus(404);
        $this->withHeaders(['Authorization' => "Bearer {$doctorAToken}"])->patchJson("/api/me/notifications/{$notificationId}/read")->assertStatus(200);
        $this->assertNotNull(DB::table('user_notifications')->where('id', $notificationId)->value('read_at'));

        $all = $this->withHeaders(['Authorization' => "Bearer {$adminToken}"])
            ->postJson('/api/events', [
                'titleEn' => 'All Specialties Event',
                'titleAr' => 'All Specialties Event',
                'startsAt' => now()->addDays(11)->toDateTimeString(),
                'endsAt' => now()->addDays(11)->addHours(2)->toDateTimeString(),
                'googleMapsUrl' => 'https://maps.example/all',
                'status' => 'published',
                'targetAllSpecialties' => true,
            ])->assertStatus(200)->json('data.id');

        $this->assertDatabaseHas('user_notifications', ['user_id' => $doctorA->id, 'entity_id' => $all]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $doctorB->id, 'entity_id' => $all]);
        $this->getJson('/api/public/events?limit=5')->assertStatus(200);
    }
}
