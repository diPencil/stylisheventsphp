<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

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
            'email_verified_at' => now(),
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

    public function test_password_reset_flow()
    {
        $role = Role::where('code', 'customer')->first();
        $email = 'pw_reset_' . uniqid() . '@example.com';
        $user = User::create([
            'role_id' => $role->id, 'name' => 'Reset User', 'email' => $email,
            'password_hash' => Hash::make('password123'), 'status' => 'active',
            'email_verified_at' => now(), 'preferred_language' => 'en',
        ]);

        $this->postJson('/api/auth/forgot-password', ['login' => $email])->assertStatus(200);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $email]);

        // Unknown token fails.
        $this->postJson('/api/auth/reset-password', [
            'email' => $email, 'token' => str_repeat('a', 64), 'password' => 'newpassword123',
        ])->assertStatus(422);

        // Extract the real token path: token is hashed at rest, so issue a known one.
        $known = bin2hex(random_bytes(32));
        DB::table('password_reset_tokens')->where('email', $email)->update([
            'token' => Hash::make($known),
            'created_at' => now(),
        ]);

        $this->postJson('/api/auth/reset-password', [
            'email' => $email, 'token' => $known, 'password' => 'newpassword123',
        ])->assertStatus(200);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $email]);

        $user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $user->password_hash));

        // Token is single-use.
        $this->postJson('/api/auth/reset-password', [
            'email' => $email, 'token' => $known, 'password' => 'anotherpass123',
        ])->assertStatus(422);
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

    public function test_me_returns_owned_customer_profile_fields()
    {
        $role = Role::where('code', 'customer')->first();
        if (!$role) {
            $this->markTestSkipped('Customer role not found in DB.');
        }

        $user = User::create([
            'role_id' => $role->id,
            'name' => 'Prefill User',
            'email' => 'prefill_user@example.com',
            'phone' => '+201000000001',
            'country_code' => 'SA',
            'country_name' => 'Saudi Arabia',
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'preferred_language' => 'en'
        ]);

        DB::table('doctors')->insert([
            'user_id' => $user->id,
            'full_name' => 'Dr Prefill User',
            'mobile' => '+201000000002',
            'email' => 'prefill_user@example.com',
            'address' => 'Prefill Address',
            'country_code' => 'SA',
            'country_name' => 'Saudi Arabia',
            'city' => 'Riyadh',
            'specialty' => 'Cardiology',
            'nationality' => 'Saudi',
            'preferred_language' => 'en',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = Auth::guard('api')->createToken($user);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.customer_full_name', 'Dr Prefill User')
            ->assertJsonPath('data.customer_mobile', '+201000000002')
            ->assertJsonPath('data.customer_city', 'Riyadh')
            ->assertJsonPath('data.customer_specialty', 'Cardiology')
            ->assertJsonPath('data.customer_nationality', 'Saudi');
    }

    public function test_register_requires_email_verification_before_login()
    {
        $email = 'verify_flow_' . uniqid() . '@example.com';
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Verify User',
            'email' => $email,
            'password' => 'password123',
            'countryCode' => 'EG',
            'countryName' => 'Egypt',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.needsVerification', true);
        $this->assertArrayNotHasKey('token', $response->json('data'));
        $this->assertDatabaseHas('users', ['email' => $email]);
        $userId = DB::table('users')->where('email', $email)->value('id');
        $this->assertNull(DB::table('users')->where('id', $userId)->value('email_verified_at'));

        // Login is blocked until verification.
        $this->postJson('/api/auth/login', ['login' => $email, 'password' => 'password123'])
            ->assertStatus(403);

        // Wrong code fails.
        $this->postJson('/api/auth/verify-email', ['email' => $email, 'code' => '000000'])
            ->assertStatus(422);

        // A code was issued; total the flow by verifying directly is covered
        // through the code record path below using a known code.
        $code = '123456';
        DB::table('email_verification_codes')->where('user_id', $userId)->delete();
        DB::table('email_verification_codes')->insert([
            'user_id' => $userId,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
        ]);

        $verify = $this->postJson('/api/auth/verify-email', ['email' => $email, 'code' => $code]);
        $verify->assertStatus(200)->assertJsonStructure(['success', 'message', 'data' => ['user', 'token']]);
        $this->assertNotNull(DB::table('users')->where('id', $userId)->value('email_verified_at'));

        // Login works after verification.
        $this->postJson('/api/auth/login', ['login' => $email, 'password' => 'password123'])
            ->assertStatus(200)->assertJsonStructure(['success', 'message', 'data' => ['user', 'token']]);
    }

    public function test_register_can_skip_email_verification_when_admin_setting_is_disabled()
    {
        Cache::forget('project_settings:email_settings');
        DB::table('project_settings')->updateOrInsert(
            ['setting_key' => 'email_settings'],
            [
                'setting_value' => json_encode(['auth' => ['emailVerificationEnabled' => false]]),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $email = 'verify_disabled_' . uniqid() . '@example.com';
        $response = $this->postJson('/api/auth/register', [
            'name' => 'No Verify User',
            'email' => $email,
            'password' => 'password123',
            'countryCode' => 'EG',
            'countryName' => 'Egypt',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.needsVerification', false)
            ->assertJsonPath('data.mailSent', false)
            ->assertJsonStructure(['success', 'message', 'data' => ['user', 'token']]);

        $userId = DB::table('users')->where('email', $email)->value('id');
        $this->assertNotNull(DB::table('users')->where('id', $userId)->value('email_verified_at'));
        $this->assertDatabaseMissing('email_verification_codes', ['user_id' => $userId]);

        $this->postJson('/api/auth/login', ['login' => $email, 'password' => 'password123'])
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'message', 'data' => ['user', 'token']]);

        Cache::forget('project_settings:email_settings');
    }

    public function test_profile_email_change_rejects_taken_email()
    {
        $role = Role::where('code', 'customer')->first();
        $first = User::create([
            'role_id' => $role->id, 'name' => 'Taken Email', 'email' => 'taken_email_x@example.com',
            'password_hash' => Hash::make('password123'), 'status' => 'active',
            'email_verified_at' => now(), 'preferred_language' => 'en',
        ]);
        $second = User::create([
            'role_id' => $role->id, 'name' => 'Second User', 'email' => 'second_user_x@example.com',
            'password_hash' => Hash::make('password123'), 'status' => 'active',
            'email_verified_at' => now(), 'preferred_language' => 'en',
        ]);

        $token = Auth::guard('api')->createToken($second);
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->patchJson('/api/auth/me', ['email' => 'taken_email_x@example.com'])
            ->assertStatus(400);
    }
}
