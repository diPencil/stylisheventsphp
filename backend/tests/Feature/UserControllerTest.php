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
}
