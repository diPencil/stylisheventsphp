<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RoleControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUpAdmin()
    {
        $role = Role::where('code', 'admin')->first();
        if (!$role) {
            $this->markTestSkipped('Admin role not found in DB.');
        }

        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'permission_key' => 'roles.manage'],
            ['allowed' => 1]
        );

        $user = User::create([
            'role_id' => $role->id,
            'name' => 'Admin User R',
            'email' => 'admin_r@example.com',
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'preferred_language' => 'en'
        ]);

        return Auth::guard('api')->createToken($user);
    }

    public function test_list_roles()
    {
        $token = $this->setUpAdmin();
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/users/roles');
        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'message', 'data' => ['catalog', 'roles']]);

        $roles = collect($response->json('data.roles'));
        $doctor = $roles->firstWhere('code', 'doctor');
        $this->assertNotNull($doctor);
        $this->assertSame('Doctor', $doctor['nameEn']);

        $doctorPermissions = collect($doctor['permissions']);
        $this->assertTrue((bool) $doctorPermissions->firstWhere('key', 'profile.manage')['allowed']);
        $this->assertFalse((bool) $doctorPermissions->firstWhere('key', 'dashboard.view')['allowed']);
        $this->assertFalse((bool) $doctorPermissions->firstWhere('key', 'users.manage')['allowed']);

        $manual = collect($response->json('data.catalog'))->firstWhere('key', 'registrations.create_manual');
        $this->assertSame('Create registrations manually', $manual['labelEn']);
        $this->assertSame('إنشاء تسجيلات يدويًا', $manual['labelAr']);
    }
}
