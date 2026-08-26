<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class PublicSignupTamperingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_reject_roleCode_admin()
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Bad Role',
            'email' => 'badrole1@example.com',
            'password' => 'password123',
            'countryCode' => 'EG',
            'countryName' => 'Egypt',
            'roleCode' => 'admin',
        ]);
        $response->assertStatus(400);
    }

    public function test_reject_accountType_admin()
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Bad Role',
            'email' => 'badrole2@example.com',
            'password' => 'password123',
            'countryCode' => 'EG',
            'countryName' => 'Egypt',
            'accountType' => 'admin',
        ]);
        $response->assertStatus(400);
    }

    public function test_reject_role_id_present()
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Bad Role',
            'email' => 'badrole3@example.com',
            'password' => 'password123',
            'countryCode' => 'EG',
            'countryName' => 'Egypt',
            'role_id' => 1,
        ]);
        $response->assertStatus(400);
    }

    public function test_reject_unknown_role()
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Bad Role',
            'email' => 'badrole4@example.com',
            'password' => 'password123',
            'countryCode' => 'EG',
            'countryName' => 'Egypt',
            'roleCode' => 'mystery_role',
        ]);
        $response->assertStatus(400);
    }

    public function test_accept_customer_and_doctor()
    {
        // Customer
        $r1 = $this->postJson('/api/auth/register', [
            'name' => 'Good Customer',
            'email' => 'good_customer@example.com',
            'password' => 'password123',
            'countryCode' => 'EG',
            'countryName' => 'Egypt',
            'accountType' => 'customer',
        ]);
        $r1->assertStatus(200);

        // Doctor requires specialty
        $specialtyId = DB::table('specialties')->insertGetId(['name_en' => 'Cardio', 'name_ar' => 'قلب', 'is_active' => 1]);
        $r2 = $this->postJson('/api/auth/register', [
            'name' => 'Good Doctor',
            'email' => 'good_doctor@example.com',
            'password' => 'password123',
            'countryCode' => 'EG',
            'countryName' => 'Egypt',
            'accountType' => 'doctor',
            'specialtyId' => $specialtyId,
        ]);
        $r2->assertStatus(200);
    }
}
