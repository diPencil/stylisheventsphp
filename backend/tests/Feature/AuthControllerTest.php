<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class AuthControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_successful_login()
    {
        $role = Role::where('code', 'customer')->first();
        if (!$role) {
            $this->markTestSkipped('Customer role not found in DB.');
        }

        $user = User::create([
            'role_id' => $role->id,
            'name' => 'Test User',
            'email' => 'test_login_x@example.com',
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'preferred_language' => 'en'
        ]);

        $response = $this->postJson('/api/auth/login', [
            'login' => 'test_login_x@example.com',
            'password' => 'password123'
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['success', 'message', 'data' => ['user', 'token']]);
    }

    public function test_invalid_password()
    {
        $role = Role::where('code', 'customer')->first();
        $user = User::create([
            'role_id' => $role->id,
            'name' => 'Test User',
            'email' => 'test_login_y@example.com',
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'preferred_language' => 'en'
        ]);

        $response = $this->postJson('/api/auth/login', [
            'login' => 'test_login_y@example.com',
            'password' => 'wrongpassword'
        ]);

        $response->assertStatus(401);
    }

    public function test_unknown_user()
    {
        $response = $this->postJson('/api/auth/login', [
            'login' => 'does_not_exist@example.com',
            'password' => 'password123'
        ]);

        $response->assertStatus(401);
    }

    public function test_disabled_user()
    {
        $role = Role::where('code', 'customer')->first();
        $user = User::create([
            'role_id' => $role->id,
            'name' => 'Test User',
            'email' => 'test_login_z@example.com',
            'password_hash' => Hash::make('password123'),
            'status' => 'inactive',
            'preferred_language' => 'en'
        ]);

        $response = $this->postJson('/api/auth/login', [
            'login' => 'test_login_z@example.com',
            'password' => 'password123'
        ]);

        $response->assertStatus(401);
    }

    public function test_register()
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'New User',
            'email' => 'new_user_reg@example.com',
            'password' => 'password123',
            'countryCode' => 'US',
            'countryName' => 'United States'
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['email' => 'new_user_reg@example.com']);
    }

    public function test_forgot_password()
    {
        $response = $this->postJson('/api/auth/forgot-password', [
            'login' => 'does_not_exist@example.com'
        ]);

        $response->assertStatus(200);
    }

    public function test_patch_profile()
    {
        $role = Role::where('code', 'customer')->first();
        $user = User::create([
            'role_id' => $role->id,
            'name' => 'Test User',
            'email' => 'test_patch@example.com',
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'preferred_language' => 'en'
        ]);

        $token = Auth::guard('api')->createToken($user);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->patchJson('/api/auth/me', [
            'name' => 'Updated Name'
        ]);


        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated Name']);
    }

    public function test_patch_password()
    {
        $role = Role::where('code', 'customer')->first();
        $user = User::create([
            'role_id' => $role->id,
            'name' => 'Test User',
            'email' => 'test_pw@example.com',
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'preferred_language' => 'en'
        ]);

        $token = Auth::guard('api')->createToken($user);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->patchJson('/api/auth/me/password', [
            'currentPassword' => 'password123',
            'newPassword' => 'newpassword123'
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $user->password_hash));
    }
}
