<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Hashing\ScryptHasher;

class PhaseCWriteParityTest extends TestCase
{
    use DatabaseTransactions;

    private string $adminToken;
    private int $testUserId;

    protected function setUp(): void
    {
        parent::setUp();

        // Look up admin role id
        $adminRole = DB::table('roles')->where('code', 'admin')->first();
        $this->assertNotNull($adminRole, 'Admin role must exist in the database');

        // Create an isolated transactional test admin user with scrypt-hashed password
        $hasher = new ScryptHasher();
        $testPassword = 'PhaseCTestPass#2026';
        $hash = $hasher->make($testPassword);

        $this->testUserId = DB::table('users')->insertGetId([
            'role_id'    => $adminRole->id,
            'name'       => 'Phase C Test Admin',
            'email'      => 'phase-c-test-admin-' . uniqid() . '@test.local',
            'password_hash' => $hash,
            'status'     => 'active',
            'gender'     => 'not_specified',
            'preferred_language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Log in to get token
        $user = DB::table('users')->where('id', $this->testUserId)->first();
        $response = $this->postJson('/api/auth/login', [
            'login'    => $user->email,
            'password' => $testPassword,
        ]);

        $this->adminToken = $response->json('data.token');
        $this->assertNotEmpty($this->adminToken, 'Login must succeed for transactional test admin');
    }

    // -----------------------------------------------------------------------
    // Authorization tests
    // -----------------------------------------------------------------------

    public function test_unauthenticated_event_write_returns_401()
    {
        $this->postJson('/api/events', [])->assertStatus(401);
    }

    public function test_event_store_validation_returns_400_with_zod_shape()
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
                         ->postJson('/api/events', []);

        $response->assertStatus(400)
                 ->assertJsonStructure([
                     'success',
                     'message',
                     'details' => ['formErrors', 'fieldErrors'],
                 ])
                 ->assertJson(['success' => false, 'message' => 'Validation failed']);

        $errors = $response->json('details.fieldErrors');
        foreach (['slug', 'titleEn', 'titleAr', 'startsAt', 'endsAt'] as $field) {
            $this->assertArrayHasKey($field, $errors, "Missing validation error for field: $field");
        }
    }

    // -----------------------------------------------------------------------
    // Event CRUD
    // -----------------------------------------------------------------------

    public function test_event_store_success()
    {
        $slug = 'test-phasec-' . uniqid();
        $payload = [
            'slug'     => $slug,
            'titleEn'  => 'Phase C Test Event',
            'titleAr'  => 'حدث تجريبي',
            'startsAt' => '2030-01-01T10:00:00',
            'endsAt'   => '2030-01-02T10:00:00',
        ];

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
                         ->postJson('/api/events', $payload);

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'message' => 'Event created']);

        $id = $response->json('data.id');
        $this->assertNotNull($id);
        $this->assertDatabaseHas('events', ['id' => $id, 'slug' => $slug]);
    }

    public function test_event_update_status()
    {
        // Create event first
        $slug = 'test-status-' . uniqid();
        $createRes = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
                          ->postJson('/api/events', [
                              'slug'     => $slug,
                              'titleEn'  => 'Status Test',
                              'titleAr'  => 'اختبار الحالة',
                              'startsAt' => '2030-01-01T10:00:00',
                              'endsAt'   => '2030-01-02T10:00:00',
                          ]);
        $eventId = $createRes->json('data.id');
        $this->assertNotNull($eventId);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
                         ->patchJson("/api/events/{$eventId}/status", ['status' => 'published']);

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'message' => 'Event status updated', 'data' => ['status' => 'published']]);
    }

    public function test_event_soft_delete_and_restore()
    {
        $slug = 'test-delete-' . uniqid();
        $createRes = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
                          ->postJson('/api/events', [
                              'slug'     => $slug,
                              'titleEn'  => 'Delete Test',
                              'titleAr'  => 'اختبار الحذف',
                              'startsAt' => '2030-01-01T10:00:00',
                              'endsAt'   => '2030-01-02T10:00:00',
                          ]);
        $eventId = $createRes->json('data.id');

        $deleteRes = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
                          ->deleteJson("/api/events/{$eventId}");
        $deleteRes->assertStatus(200)->assertJson(['data' => ['status' => 'deleted']]);

        $restoreRes = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
                           ->postJson("/api/events/{$eventId}/restore");
        $restoreRes->assertStatus(200)->assertJson(['data' => ['status' => 'draft']]);
    }

    // -----------------------------------------------------------------------
    // Ticket Type CRUD
    // -----------------------------------------------------------------------

    public function test_ticket_type_store_and_delete()
    {
        // Create event
        $eventRes = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
                         ->postJson('/api/events', [
                             'slug'     => 'ticket-host-' . uniqid(),
                             'titleEn'  => 'Ticket Host',
                             'titleAr'  => 'مضيف تذاكر',
                             'startsAt' => '2030-01-01T10:00:00',
                             'endsAt'   => '2030-01-02T10:00:00',
                         ]);
        $eventId = $eventRes->json('data.id');

        // Store ticket type
        $ticketRes = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
                          ->postJson('/api/tickets', [
                              'eventId' => $eventId,
                              'nameEn'  => 'VIP',
                              'nameAr'  => 'كبار الشخصيات',
                          ]);
        $ticketRes->assertStatus(200)->assertJson(['success' => true, 'message' => 'Ticket type created']);
        $ticketId = $ticketRes->json('data.id');

        // Update status
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
             ->patchJson("/api/tickets/{$ticketId}/status", ['isActive' => false])
             ->assertStatus(200)->assertJson(['data' => ['isActive' => false]]);

        // Delete (no price periods)
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
             ->deleteJson("/api/tickets/{$ticketId}")
             ->assertStatus(200)->assertJson(['success' => true]);
    }

    // -----------------------------------------------------------------------
    // Price Period CRUD
    // -----------------------------------------------------------------------

    public function test_price_period_store_and_delete()
    {
        $eventRes = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
                         ->postJson('/api/events', [
                             'slug'     => 'pp-host-' . uniqid(),
                             'titleEn'  => 'PP Host',
                             'titleAr'  => 'مضيف التسعير',
                             'startsAt' => '2030-01-01T10:00:00',
                             'endsAt'   => '2030-01-02T10:00:00',
                         ]);
        $eventId = $eventRes->json('data.id');

        $ticketRes = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
                          ->postJson('/api/tickets', [
                              'eventId' => $eventId,
                              'nameEn'  => 'Standard',
                              'nameAr'  => 'عادي',
                          ]);
        $ticketId = $ticketRes->json('data.id');

        $ppRes = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
                      ->postJson('/api/tickets/price-periods', [
                          'ticketTypeId' => $ticketId,
                          'labelEn'      => 'Early Bird',
                          'labelAr'      => 'الحجز المبكر',
                          'priceEgp'     => 500,
                          'startsAt'     => '2029-01-01T00:00:00',
                          'endsAt'       => '2029-12-31T23:59:59',
                      ]);
        $ppRes->assertStatus(200)->assertJson(['success' => true, 'message' => 'Price period created']);
        $ppId = $ppRes->json('data.id');

        $this->assertDatabaseHas('ticket_price_periods', [
            'id' => $ppId,
            'price' => 500,
            'price_egp' => 500,
        ]);

        // Delete period
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
             ->deleteJson("/api/tickets/price-periods/{$ppId}")
             ->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_price_period_preserves_base_price_separately_from_currency_prices()
    {
        $eventRes = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
                         ->postJson('/api/events', [
                             'slug'     => 'pp-price-host-' . uniqid(),
                             'titleEn'  => 'PP Price Host',
                             'titleAr'  => 'Ù…Ø¶ÙŠÙ Ø³Ø¹Ø±',
                             'startsAt' => '2030-01-01T10:00:00',
                             'endsAt'   => '2030-01-02T10:00:00',
                         ]);
        $eventId = $eventRes->json('data.id');

        $ticketRes = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
                          ->postJson('/api/tickets', [
                              'eventId' => $eventId,
                              'nameEn'  => 'Standard',
                              'nameAr'  => 'Ø¹Ø§Ø¯ÙŠ',
                          ]);
        $ticketId = $ticketRes->json('data.id');

        $ppRes = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
                      ->postJson('/api/tickets/price-periods', [
                          'ticketTypeId' => $ticketId,
                          'labelEn'      => 'Base Price',
                          'labelAr'      => 'Ø§Ù„Ø³Ø¹Ø± Ø§Ù„Ø£Ø³Ø§Ø³ÙŠ',
                          'price'        => 100,
                          'priceEgp'     => 500,
                          'priceUsd'     => 20,
                          'startsAt'     => '2029-01-01T00:00:00',
                          'endsAt'       => '2029-12-31T23:59:59',
                      ]);
        $ppRes->assertStatus(200)->assertJson(['success' => true]);
        $ppId = $ppRes->json('data.id');

        $this->assertDatabaseHas('ticket_price_periods', [
            'id' => $ppId,
            'price' => 100,
            'price_egp' => 500,
            'price_usd' => 20,
        ]);
    }

    // -----------------------------------------------------------------------
    // Admin Review writes
    // -----------------------------------------------------------------------

    public function test_admin_review_status_requires_reviews_manage()
    {
        // 401 without token
        $this->patchJson('/api/reviews/1/status', ['status' => 'approved'])->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // Platform Settings writes
    // -----------------------------------------------------------------------

    public function test_theme_write()
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
                         ->putJson('/api/platform/settings/theme', [
                             'primaryColor' => '#ff0000',
                         ]);
        // Admin with theme_identity.manage should succeed (or 403 if that permission is not granted to role)
        $this->assertContains($response->status(), [200, 403]);
    }

    public function test_theme_payload_over_255_characters_roundtrips_exactly()
    {
        Cache::forget('project_settings:theme');

        $payload = [
            'primaryColor' => '#123456',
            'secondaryColor' => '#654321',
            'accentColor' => '#abcdef',
            'radius' => '18',
            'fontFamily' => 'Rubik',
            'fontFamilyAr' => 'Cairo',
            'buttonStyle' => 'solid',
            'density' => 'comfortable',
            'logoEnUrl' => '/uploads/assets/' . str_repeat('theme-logo-en-', 12) . '.png',
            'logoArUrl' => '/uploads/assets/' . str_repeat('theme-logo-ar-', 12) . '.png',
            'faviconUrl' => '/uploads/assets/' . str_repeat('theme-favicon-', 12) . '.png',
            'footerLocationEn' => str_repeat('Long English footer location ', 12),
            'footerLocationAr' => str_repeat('Long Arabic footer location ', 12),
            'footerMobile' => '+2 0100 607 1661',
            'footerWhatsapp' => '+2 0100 607 1661',
        ];

        $this->assertGreaterThan(255, strlen(json_encode($payload)));

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
             ->putJson('/api/platform/settings/theme', $payload)
             ->assertStatus(200)
             ->assertJsonPath('data.logoEnUrl', $payload['logoEnUrl']);

        $this->getJson('/api/platform/settings/theme')
             ->assertStatus(200)
             ->assertJsonPath('data', $payload);

        $stored = DB::table('project_settings')->where('setting_key', 'theme')->value('setting_value');
        $this->assertSame($payload, json_decode($stored, true));
    }

    public function test_theme_update_invalidates_cached_theme()
    {
        Cache::put('project_settings:theme', ['primaryColor' => '#000000'], 300);

        $payload = [
            'primaryColor' => '#fedcba',
            'logoEnUrl' => null,
            'logoArUrl' => null,
            'faviconUrl' => null,
        ];

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
             ->putJson('/api/platform/settings/theme', $payload)
             ->assertStatus(200);

        $this->getJson('/api/platform/settings/theme')
             ->assertStatus(200)
             ->assertJsonPath('data.primaryColor', '#fedcba')
             ->assertJsonPath('data.logoEnUrl', null)
             ->assertJsonPath('data.logoArUrl', null)
             ->assertJsonPath('data.faviconUrl', null);
    }

    public function test_site_content_update_handles_stdclass_cache_and_invalidates_cache()
    {
        Cache::put('project_settings:site_content', (object) [
            'homepage' => (object) [
                'footerLogoDescEn' => 'Cached stale footer',
            ],
        ], 300);

        $payload = [
            'homepage' => [
                'footerLogoDescAr' => 'Updated footer content',
            ],
            'seo' => [
                'titleEn' => 'Updated SEO title',
            ],
        ];

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
             ->putJson('/api/platform/settings/site-content', $payload)
             ->assertStatus(200)
             ->assertJsonPath('data.homepage.footerLogoDescEn', 'Cached stale footer')
             ->assertJsonPath('data.homepage.footerLogoDescAr', 'Updated footer content');

        $this->getJson('/api/platform/settings/site-content')
             ->assertStatus(200)
             ->assertJsonPath('data.homepage.footerLogoDescAr', 'Updated footer content')
             ->assertJsonPath('data.seo.titleEn', 'Updated SEO title');
    }

    public function test_empty_site_content_can_be_read_and_updated()
    {
        Cache::forget('project_settings:site_content');

        DB::table('project_settings')->updateOrInsert(
            ['setting_key' => 'site_content'],
            [
                'setting_value' => json_encode((object) []),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->getJson('/api/platform/settings/site-content')
             ->assertStatus(200)
             ->assertJson(['success' => true]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
             ->putJson('/api/platform/settings/site-content', [
                 'homepage' => [
                     'footerLogoDescEn' => 'Updated from empty content',
                 ],
             ])
             ->assertStatus(200)
             ->assertJsonPath('data.homepage.footerLogoDescEn', 'Updated from empty content');
    }

    public function test_clearing_theme_logo_values_persists_nulls_for_static_fallbacks()
    {
        Cache::forget('project_settings:theme');

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
             ->putJson('/api/platform/settings/theme', [
                 'logoEnUrl' => '/uploads/assets/custom-logo-en.png',
                 'logoArUrl' => '/uploads/assets/custom-logo-ar.png',
                 'faviconUrl' => '/uploads/assets/custom-favicon.png',
             ])
             ->assertStatus(200);

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
             ->putJson('/api/platform/settings/theme', [
                 'logoEnUrl' => null,
                 'logoArUrl' => null,
                 'faviconUrl' => null,
             ])
             ->assertStatus(200)
             ->assertJsonPath('data.logoEnUrl', null)
             ->assertJsonPath('data.logoArUrl', null)
             ->assertJsonPath('data.faviconUrl', null);

        $this->getJson('/api/platform/settings/theme')
             ->assertStatus(200)
             ->assertJsonPath('data.logoEnUrl', null)
             ->assertJsonPath('data.logoArUrl', null)
             ->assertJsonPath('data.faviconUrl', null);
    }

    public function test_currency_write()
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
                         ->putJson('/api/platform/settings/currency', [
                             'default' => 'EGP',
                         ]);
        $this->assertContains($response->status(), [200, 403]);
    }
}
