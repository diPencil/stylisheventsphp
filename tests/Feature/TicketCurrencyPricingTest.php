<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

class TicketCurrencyPricingTest extends TestCase
{
    use DatabaseTransactions;

    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $roleIdAdmin = DB::table('roles')->where('code', 'admin')->value('id');
        $adminId = DB::table('users')->insertGetId([
            'role_id' => $roleIdAdmin,
            'name' => 'Currency Test Admin',
            'email' => 'admin.currency.' . uniqid() . '@test.local',
            'password_hash' => 'hash',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $this->admin = User::find($adminId);

        foreach (['events.manage', 'tickets.manage', 'pricing.manage'] as $perm) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleIdAdmin, 'permission_key' => $perm],
                ['allowed' => 1, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        // Deterministic rates: 1 USD = 50 EGP.
        DB::table('project_settings')->updateOrInsert(
            ['setting_key' => 'currency'],
            ['setting_value' => json_encode([
                'baseCurrency' => 'USD',
                'rates' => [
                    ['code' => 'USD', 'rate' => 1],
                    ['code' => 'EGP', 'rate' => 50],
                ],
            ]), 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function test_egyptian_customer_pays_converted_egp_for_usd_ticket()
    {
        $slug = 'currency-event-' . Str::random(6);
        $eventId = $this->actingAs($this->admin, 'api')->postJson('/api/events', [
            'titleEn' => 'Currency Event',
            'slug' => $slug,
            'startsAt' => Carbon::now()->addDays(10)->toIso8601String(),
            'endsAt' => Carbon::now()->addDays(12)->toIso8601String(),
            'status' => 'published',
            'type' => 'conference',
        ])->assertStatus(200)->json('data.id');

        $ticketId = $this->actingAs($this->admin, 'api')->postJson('/api/tickets', [
            'eventId' => $eventId,
            'nameEn' => 'General',
            'nameAr' => 'عام',
            'quota' => 100,
            'isActive' => true,
        ])->assertStatus(200)->json('data.id');

        // USD-only period: no EGP column value stored.
        $this->actingAs($this->admin, 'api')->postJson('/api/tickets/price-periods', [
            'ticketTypeId' => $ticketId,
            'labelEn' => 'Standard',
            'labelAr' => 'Standard',
            'price' => 50,
            'priceUsd' => 50,
            'currency' => 'USD',
            'startsAt' => Carbon::now()->subDay()->toIso8601String(),
            'endsAt' => Carbon::now()->addDays(5)->toIso8601String(),
            'isActive' => true,
        ])->assertStatus(200);

        $this->assertDatabaseHas('ticket_price_periods', ['ticket_type_id' => $ticketId, 'currency' => 'USD']);

        // Egyptian customer sees and pays converted EGP (50 * 50), never 0.
        $egEmail = 'eg-customer-' . uniqid() . '@test.local';
        $egRes = $this->postJson("/api/public/events/{$slug}/checkout", [
            'idempotencyKey' => Str::random(16),
            'ticketTypeId' => $ticketId,
            'quantity' => 1,
            'fullName' => 'Egyptian Customer',
            'email' => $egEmail,
            'mobile' => '01000000000',
            'countryCode' => 'EG',
            'countryName' => 'Egypt',
        ])->assertStatus(200);

        $egRow = DB::table('registrations')->where('id', $egRes->json('data.registration.id'))->first();
        $this->assertNotNull($egRow);
        $this->assertEquals('EGP', $egRow->selected_currency);
        $this->assertEquals(2500, (float) $egRow->selected_price);

        // Foreign customer pays raw USD.
        $usEmail = 'us-customer-' . uniqid() . '@test.local';
        $usRes = $this->postJson("/api/public/events/{$slug}/checkout", [
            'idempotencyKey' => Str::random(16),
            'ticketTypeId' => $ticketId,
            'quantity' => 1,
            'fullName' => 'American Customer',
            'email' => $usEmail,
            'mobile' => '15550001111',
            'countryCode' => 'US',
            'countryName' => 'United States',
        ])->assertStatus(200);

        $usRow = DB::table('registrations')->where('id', $usRes->json('data.registration.id'))->first();
        $this->assertNotNull($usRow);
        $this->assertEquals('USD', $usRow->selected_currency);
        $this->assertEquals(50, (float) $usRow->selected_price);

        // Public display exposes the period currency.
        $public = $this->getJson("/api/public/events/{$slug}")->assertStatus(200);
        $this->assertEquals('USD', $public->json('data.tickets.0.currency'));
    }
}
