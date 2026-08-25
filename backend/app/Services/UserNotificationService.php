<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserNotificationService
{
    public function create(int $userId, string $type, string $titleEn, ?string $messageEn = null, array $options = []): void
    {
        if ($userId < 1 || !Schema::hasTable('user_notifications')) {
            return;
        }

        $payload = [
            'user_id' => $userId,
            'type' => $type,
            'title_en' => $titleEn,
            'title_ar' => $options['title_ar'] ?? null,
            'message_en' => $messageEn,
            'message_ar' => $options['message_ar'] ?? null,
            'entity_type' => $options['entity_type'] ?? null,
            'entity_id' => $options['entity_id'] ?? null,
            'action_url' => $options['action_url'] ?? null,
            'dedupe_key' => $options['dedupe_key'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($payload['dedupe_key']) {
            DB::table('user_notifications')->insertOrIgnore($payload);
            return;
        }

        DB::table('user_notifications')->insert($payload);
    }

    public function notifyRegistrationUser(int $registrationId, string $type, string $titleEn, string $messageEn, array $options = []): void
    {
        $row = DB::table('registrations as r')
            ->join('doctors as d', 'd.id', '=', 'r.doctor_id')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->where('r.id', $registrationId)
            ->select('r.id', 'd.user_id', 'e.slug', 'e.title_en', 'e.title_ar')
            ->first();

        if (!$row || !$row->user_id) {
            return;
        }

        $this->create((int) $row->user_id, $type, $titleEn, $messageEn, array_merge([
            'title_ar' => $options['title_ar'] ?? null,
            'message_ar' => $options['message_ar'] ?? null,
            'entity_type' => 'registration',
            'entity_id' => $registrationId,
            'action_url' => '/dashboard/registrations/' . $registrationId,
            'dedupe_key' => $type . ':registration:' . $registrationId,
        ], $options));
    }

    public function notifyDoctorsForPublishedEvent(int $eventId): void
    {
        if (!Schema::hasTable('event_specialty') || !Schema::hasTable('user_notifications')) {
            return;
        }

        $event = DB::table('events')->where('id', $eventId)->where('status', 'published')->first();
        if (!$event) {
            return;
        }

        $title = $event->title_en ?: 'New event';
        $titleAr = $event->title_ar ?: $title;
        $url = '/events/' . $event->slug;

        if ((int)($event->target_all_specialties ?? 0) === 1) {
            DB::statement("
                INSERT IGNORE INTO user_notifications
                    (user_id, type, title_en, title_ar, message_en, message_ar, entity_type, entity_id, action_url, dedupe_key, created_at, updated_at)
                SELECT u.id, 'event_for_specialty', ?, ?, ?, ?, 'event', e.id, ?, CONCAT('event_for_specialty:event:', e.id), NOW(), NOW()
                FROM users u
                JOIN roles r ON r.id = u.role_id AND r.code = 'doctor'
                JOIN doctors d ON d.user_id = u.id
                JOIN events e ON e.id = ?
                WHERE u.status = 'active' AND d.specialty_id IS NOT NULL
            ", ['New Event for Your Specialty', 'فعالية جديدة تناسب تخصصك', $title, $titleAr, $url, $eventId]);
            return;
        }

        DB::statement("
            INSERT IGNORE INTO user_notifications
                (user_id, type, title_en, title_ar, message_en, message_ar, entity_type, entity_id, action_url, dedupe_key, created_at, updated_at)
            SELECT DISTINCT u.id, 'event_for_specialty', ?, ?, ?, ?, 'event', e.id, ?, CONCAT('event_for_specialty:event:', e.id), NOW(), NOW()
            FROM events e
            JOIN event_specialty es ON es.event_id = e.id
            JOIN doctors d ON d.specialty_id = es.specialty_id
            JOIN users u ON u.id = d.user_id AND u.status = 'active'
            JOIN roles r ON r.id = u.role_id AND r.code = 'doctor'
            WHERE e.id = ? AND e.status = 'published'
        ", ['New Event for Your Specialty', 'فعالية جديدة تناسب تخصصك', $title, $titleAr, $url, $eventId]);
    }
}
