<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class AdminUserApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeAdminToken()
    {
        $role = DB::table('roles')->where('code', 'admin')->first();
        if (!$role) {
            $roleId = DB::table('roles')->insertGetId(['code' => 'admin', 'name_en' => 'Administrator', 'created_at' => now()]);
        } else {
            $roleId = $role->id;
        }

        $userId = DB::table('users')->insertGetId([
            'name' => 'Local Admin',
            'email' => 'local_admin_test@example.com',
            'password_hash' => 'hash',
            'status' => 'active',
            'role_id' => $roleId,
        ]);

        $user = User::find($userId);
        return 'Bearer ' . app('auth')->guard('api')->createToken($user);
    }

    public function test_admin_create_customer_and_doctor_and_uniqueness()
    {
        $token = $this->makeAdminToken();

        $payload = [
            'name' => 'API Customer',
            'email' => 'api_customer_test@example.com',
            'username' => 'api_customer1',
            'password' => 'password123',
            'countryCode' => 'EG',
            'countryName' => 'Egypt',
            'roleCode' => 'customer',
        ];

        $r = $this->withHeaders(['Authorization' => $token])->postJson('/api/users', $payload);
        $r->assertStatus(200);
        $this->assertDatabaseHas('users', ['email' => $payload['email'], 'username' => $payload['username']]);

        // Duplicate email -> rejected (validation mapped to 400 in API)
        $r2 = $this->withHeaders(['Authorization' => $token])->postJson('/api/users', array_merge($payload, ['username' => 'api_customer2']));
        $r2->assertStatus(400);

        // Duplicate username -> rejected
        $r3 = $this->withHeaders(['Authorization' => $token])->postJson('/api/users', array_merge($payload, ['email' => 'api_customer2@example.com']));
        $r3->assertStatus(400);

        // Create doctor requires specialty
        $r4 = $this->withHeaders(['Authorization' => $token])->postJson('/api/users', [
            'name' => 'API Doctor',
            'email' => 'api_doctor_test@example.com',
            'username' => 'api_doctor1',
            'password' => 'password123',
            'roleCode' => 'doctor',
        ]);
        $r4->assertStatus(422);

        $specId = DB::table('specialties')->insertGetId(['name_en' => 'Derm', 'name_ar' => 'جلدية', 'is_active' => 1]);
        $r5 = $this->withHeaders(['Authorization' => $token])->postJson('/api/users', [
            'name' => 'API Doctor',
            'email' => 'api_doctor_test2@example.com',
            'username' => 'api_doctor2',
            'password' => 'password123',
            'roleCode' => 'doctor',
            'specialtyId' => $specId,
        ]);
        $r5->assertStatus(200);
        $docUserId = DB::table('users')->where('email', 'api_doctor_test2@example.com')->value('id');
        $this->assertDatabaseHas('doctors', ['user_id' => $docUserId, 'specialty_id' => $specId]);
    }

    public function test_admin_edit_user_username_and_specialty_transitions()
    {
        $token = $this->makeAdminToken();
        $roleCustomer = DB::table('roles')->where('code', 'customer')->first();
        $custId = DB::table('users')->insertGetId(['name' => 'Edit Customer', 'email' => 'edit_cust@example.com', 'username' => 'editcust', 'password_hash' => 'hash', 'status' => 'active', 'role_id' => $roleCustomer->id]);

        $specId = DB::table('specialties')->insertGetId(['name_en' => 'ENT', 'name_ar' => 'أنف', 'is_active' => 1]);

        // Change customer to doctor without specialty -> should fail
        $r = $this->withHeaders(['Authorization' => $token])->putJson('/api/users/' . $custId, ['roleCode' => 'doctor']);
        $r->assertStatus(422);

        // Change to doctor with specialty -> succeed
        $r2 = $this->withHeaders(['Authorization' => $token])->putJson('/api/users/' . $custId, ['roleCode' => 'doctor', 'specialtyId' => $specId]);
        $r2->assertStatus(200);
        $this->assertDatabaseHas('doctors', ['user_id' => $custId, 'specialty_id' => $specId]);

        // Change back to customer -> specialty cleared
        $r3 = $this->withHeaders(['Authorization' => $token])->putJson('/api/users/' . $custId, ['roleCode' => 'customer']);
        $r3->assertStatus(200);
        $this->assertDatabaseMissing('doctors', ['user_id' => $custId, 'specialty_id' => $specId]);

        // Update username to new unique value
        $r4 = $this->withHeaders(['Authorization' => $token])->putJson('/api/users/' . $custId, ['username' => 'editcust2']);
        $r4->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $custId, 'username' => 'editcust2']);

        // Create another user and try duplicate username -> rejected
        $otherId = DB::table('users')->insertGetId(['name' => 'Other', 'email' => 'other@example.com', 'username' => 'other1', 'password_hash' => 'hash', 'status' => 'active', 'role_id' => $roleCustomer->id]);
        $r5 = $this->withHeaders(['Authorization' => $token])->putJson('/api/users/' . $custId, ['username' => 'other1']);
        $r5->assertStatus(400);
    }
}
