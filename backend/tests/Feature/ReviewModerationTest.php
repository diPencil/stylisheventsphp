<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

class ReviewModerationTest extends TestCase
{
    use DatabaseTransactions;

    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $roleIdAdmin = DB::table('roles')->where('code', 'admin')->value('id');
        $adminId = DB::table('users')->insertGetId([
            'role_id' => $roleIdAdmin,
            'name' => 'Review Mod Admin',
            'email' => 'admin.reviews.' . uniqid() . '@test.local',
            'password_hash' => 'hash',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $this->admin = User::find($adminId);

        foreach (['events.manage', 'reviews.view', 'reviews.manage'] as $perm) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleIdAdmin, 'permission_key' => $perm],
                ['allowed' => 1, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function test_review_publish_reject_delete_lifecycle()
    {
        $slug = 'review-event-' . Str::random(6);
        $eventId = $this->actingAs($this->admin, 'api')->postJson('/api/events', [
            'titleEn' => 'Review Event',
            'slug' => $slug,
            'startsAt' => Carbon::now()->subDays(5)->toIso8601String(),
            'endsAt' => Carbon::now()->subDays(3)->toIso8601String(),
            'status' => 'published',
            'type' => 'conference',
        ])->assertStatus(200)->json('data.id');

        $reviewId = DB::table('reviews')->insertGetId([
            'event_id' => $eventId,
            'rating' => 5,
            'title' => 'Great event',
            'comment' => 'Loved every session of this event.',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Pending reviews are invisible to the public.
        $publicBefore = $this->getJson("/api/public/events/{$slug}/reviews")->assertStatus(200);
        $this->assertCount(0, $publicBefore->json('data.items'));

        // Publish.
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/reviews/{$reviewId}/status", ['status' => 'approved'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        // Admin list maps the published filter to approved.
        $list = $this->actingAs($this->admin, 'api')
            ->getJson('/api/reviews?status=published')
            ->assertStatus(200);
        $this->assertNotEmpty(array_filter(
            $list->json('data'),
            fn($row) => (int) $row['id'] === (int) $reviewId
        ));

        // Public listing shows it now.
        $publicAfter = $this->getJson("/api/public/events/{$slug}/reviews")->assertStatus(200);
        $this->assertNotEmpty(array_filter(
            $publicAfter->json('data.items'),
            fn($row) => (int) ($row['id'] ?? 0) === (int) $reviewId
        ));

        // Invalid status is rejected.
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/reviews/{$reviewId}/status", ['status' => 'published'])
            ->assertStatus(400);

        // Reject hides it again.
        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/reviews/{$reviewId}/status", ['status' => 'rejected'])
            ->assertStatus(200);
        $publicRejected = $this->getJson("/api/public/events/{$slug}/reviews")->assertStatus(200);
        $this->assertCount(0, array_filter(
            $publicRejected->json('data.items'),
            fn($row) => (int) ($row['id'] ?? 0) === (int) $reviewId
        ));

        // Delete removes it.
        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/reviews/{$reviewId}")
            ->assertStatus(200);
        $this->assertDatabaseMissing('reviews', ['id' => $reviewId]);
    }
}
