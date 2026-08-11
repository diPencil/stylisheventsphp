<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MiddlewareTest extends TestCase
{
    use DatabaseTransactions;

    public function test_401_behavior()
    {
        $response = $this->getJson('/api/users');
        $response->assertStatus(401);
    }

    public function test_403_behavior_wrong_role()
    {
        $role = Role::where('code', 'customer')->first();
        if (!$role) {
            $this->markTestSkipped('Customer role not found in DB.');
        }

        $user = User::create([
            'role_id' => $role->id,
            'name' => 'Customer User',
            'email' => 'customer_m@example.com',
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'preferred_language' => 'en'
        ]);

        $token = Auth::guard('api')->createToken($user);

        // This endpoint requires users.manage permission. Customers don't have it by default.
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/users');

        $response->assertStatus(403);
    }

    public function test_permission_success()
    {
        $role = Role::where('code', 'admin')->first();
        if (!$role) {
            $this->markTestSkipped('Admin role not found in DB.');
        }

        $user = User::create([
            'role_id' => $role->id,
            'name' => 'Admin User',
            'email' => 'admin_m@example.com',
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'preferred_language' => 'en'
        ]);

        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'permission_key' => 'users.manage'],
            ['allowed' => 1]
        );

        $token = Auth::guard('api')->createToken($user);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/users');

        $response->assertStatus(200);
    }
}
