<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUpAdmin()
    {
        $role = Role::where('code', 'admin')->first();
        if (!$role) {
            $this->markTestSkipped('Admin role not found in DB.');
        }

        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'permission_key' => 'users.manage'],
            ['allowed' => 1]
        );

        $user = User::create([
            'role_id' => $role->id,
            'name' => 'Admin User U',
            'email' => 'admin_u@example.com',
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'preferred_language' => 'en'
        ]);

        return Auth::guard('api')->createToken($user);
    }

    public function test_list_users()
    {
        $token = $this->setUpAdmin();
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/users');
        $response->assertStatus(200);
    }

    public function test_create_user()
    {
        $token = $this->setUpAdmin();
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/users', [
            'name' => 'New Customer',
            'email' => 'new_cust_z@example.com',
            'password' => 'password123',
            'roleCode' => 'customer'
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['email' => 'new_cust_z@example.com']);
    }

    public function test_create_and_edit_doctor_user_without_admin_permissions()
    {
        $token = $this->setUpAdmin();
        $doctorRole = Role::where('code', 'doctor')->first();
        $this->assertNotNull($doctorRole);
        DB::table('role_permissions')
            ->where('role_id', $doctorRole->id)
            ->where('permission_key', 'users.manage')
            ->delete();
        DB::table('role_permissions')->insert([
            'role_id' => $doctorRole->id,
            'permission_key' => 'users.manage',
            'allowed' => 0,
        ]);
        $specialtyId = DB::table('specialties')->updateOrInsert(
            ['name_en' => 'Cardiology'],
            ['name_ar' => 'أمراض القلب', 'is_active' => 1, 'updated_at' => now(), 'created_at' => now()]
        ) ?: DB::table('specialties')->where('name_en', 'Cardiology')->value('id');
        $specialtyId = DB::table('specialties')->where('name_en', 'Cardiology')->value('id');

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/users', [
            'name' => 'Doctor User',
            'email' => 'doctor_user_' . uniqid() . '@example.com',
            'password' => 'password123',
            'roleCode' => 'doctor',
            'specialtyId' => $specialtyId,
            'status' => 'active',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.role.code', 'doctor')
            ->assertJsonPath('data.role.nameEn', 'Doctor');

        $doctorId = $response->json('data.id');
        $doctor = User::find($doctorId);
        $doctorToken = Auth::guard('api')->createToken($doctor);

        $this->withHeaders(['Authorization' => "Bearer $doctorToken"])
            ->getJson('/api/me/registrations')
            ->assertStatus(200);

        $this->withHeaders(['Authorization' => "Bearer $doctorToken"])
            ->getJson('/api/users')
            ->assertStatus(403);

        $update = $this->withHeaders(['Authorization' => "Bearer $token"])->putJson("/api/users/{$doctorId}", [
            'roleCode' => 'customer',
        ]);
        $update->assertStatus(200)
            ->assertJsonPath('data.role.code', 'customer');

        $backToDoctor = $this->withHeaders(['Authorization' => "Bearer $token"])->putJson("/api/users/{$doctorId}", [
            'roleCode' => 'doctor',
            'specialtyId' => $specialtyId,
        ]);
        $backToDoctor->assertStatus(200)
            ->assertJsonPath('data.role.code', 'doctor');
    }
}
