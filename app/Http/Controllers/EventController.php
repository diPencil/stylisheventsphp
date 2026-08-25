<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Services\UserNotificationService;

class EventController extends Controller
{
    private function eventSelect()
    {
        return "
            SELECT
              e.id,
              e.organizer_id,
              e.venue_id,
              e.slug,
              e.title_en,
              e.title_ar,
              e.summary_en,
              e.summary_ar,
              e.description_en,
              e.description_ar,
              e.type,
              e.status,
              e.starts_at,
              e.ends_at,
              e.registration_starts_at,
              e.registration_ends_at,
              e.public_registration_enabled,
              e.registration_approval_mode,
              e.registration_access,
              e.max_tickets_per_checkout,
              e.capacity_hold_hours_override,
              e.manual_payment_enabled,
              e.timezone,
              e.cover_image_url,
              e.banner_image_url,
              e.event_details_image_url,
              e.event_pdf_url,
              e.gallery_json,
              e.google_maps_url,
              e.max_attendees,
              e.target_all_specialties,
              e.created_at,
              e.updated_at,
              v.name_en AS venue_name_en,
              v.name_ar AS venue_name_ar,
              v.city_en AS venue_city_en,
              v.city_ar AS venue_city_ar,
              v.capacity AS venue_capacity,
              u.name AS organizer_name,
              COUNT(DISTINCT tt.id) AS ticket_types_count,
              COUNT(DISTINCT a.id) AS attendees_count,
              COUNT(DISTINCT r.id) AS registrations_count,
              COALESCE(AVG(rv.rating), 0) AS average_rating
            FROM events e
            LEFT JOIN venues v ON v.id = e.venue_id
            LEFT JOIN users u ON u.id = e.organizer_id
            LEFT JOIN ticket_types tt ON tt.event_id = e.id
            LEFT JOIN attendees a ON a.event_id = e.id
            LEFT JOIN registrations r ON r.event_id = e.id
            LEFT JOIN reviews rv ON rv.event_id = e.id AND rv.status = 'approved'
        ";
    }

    private function normalizeEventJsonFields($event)
    {
        // Parse dates exactly to match ISO format from mysql2 driver in node
        $formatDate = function ($date) {
            return $date ? Carbon::parse($date, 'Africa/Cairo')->setTimezone('UTC')->format('Y-m-d\TH:i:s.000\Z') : null;
        };

        // For counts returning as string from Laravel's DB facade (MySQL PDO), cast to integer
        // Also tinyints must be mapped exactly to match Node response
        return [
            'id' => (int) $event->id,
            'organizer_id' => $event->organizer_id !== null ? (int) $event->organizer_id : null,
            'venue_id' => $event->venue_id !== null ? (int) $event->venue_id : null,
            'slug' => $event->slug,
            'title_en' => $event->title_en,
            'title_ar' => $event->title_ar,
            'summary_en' => $event->summary_en,
            'summary_ar' => $event->summary_ar,
            'description_en' => $event->description_en,
            'description_ar' => $event->description_ar,
            'type' => $event->type,
            'status' => $event->status,
            'starts_at' => $formatDate($event->starts_at),
            'ends_at' => $formatDate($event->ends_at),
            'registration_starts_at' => $formatDate($event->registration_starts_at),
            'registration_ends_at' => $formatDate($event->registration_ends_at),
            'public_registration_enabled' => (int) $event->public_registration_enabled,
            'registration_approval_mode' => $event->registration_approval_mode,
            'registration_access' => $event->registration_access,
            'max_tickets_per_checkout' => (int) $event->max_tickets_per_checkout,
            'capacity_hold_hours_override' => $event->capacity_hold_hours_override !== null ? (int) $event->capacity_hold_hours_override : null,
            'manual_payment_enabled' => (int) $event->manual_payment_enabled,
            'timezone' => $event->timezone,
            'cover_image_url' => $event->cover_image_url,
            'banner_image_url' => $event->banner_image_url,
            'event_details_image_url' => $event->event_details_image_url,
            'event_pdf_url' => $event->event_pdf_url ?? null,
            'gallery_json' => $event->gallery_json !== null ? (string)$event->gallery_json : "[]", // string response expected by frontend (it does JSON.parse)
            'google_maps_url' => $event->google_maps_url,
            'max_attendees' => $event->max_attendees !== null ? (int) $event->max_attendees : null,
            'target_all_specialties' => isset($event->target_all_specialties) ? (int) $event->target_all_specialties : 0,
            'targetSpecialties' => isset($event->id) ? $this->eventSpecialties((int) $event->id) : [],
            'created_at' => $formatDate($event->created_at),
            'updated_at' => $formatDate($event->updated_at),
            'venue_name_en' => $event->venue_name_en,
            'venue_name_ar' => $event->venue_name_ar,
            'venue_city_en' => $event->venue_city_en,
            'venue_city_ar' => $event->venue_city_ar,
            'venue_capacity' => $event->venue_capacity !== null ? (int) $event->venue_capacity : null,
            'organizer_name' => $event->organizer_name,
            'ticket_types_count' => (int) $event->ticket_types_count,
            'attendees_count' => (int) $event->attendees_count,
            'registrations_count' => (int) $event->registrations_count,
            'average_rating' => number_format((float) $event->average_rating, 4, '.', '')
        ];
    }

    private function getEventScopeClause($user, $tableAlias = 'e')
    {
        // Simple scope checking for auth user
        if (!$user) return '1 = 0';
        $roles = [$user->role->code ?? ''];
        if (in_array('admin', $roles)) {
            return '1 = 1';
        }
        if (in_array('organizer', $roles)) {
            return "$tableAlias.organizer_id = " . (int)$user->id;
        }
        return '1 = 0'; // Fallback
    }

    private function publicListCacheKey(Request $request)
    {
        $version = Cache::get('public_events:list_version', 1);
        return 'public_events:list:' . md5(json_encode([
            'version' => $version,
            'status' => 'published',
            'page' => trim((string)$request->query('page', '')),
            'sortMode' => trim((string)$request->query('sortMode', 'default')),
            'limit' => max(1, min((int)$request->query('limit', 250), 500)),
        ]));
    }

    private function forgetPublicEventLists()
    {
        $version = (int) Cache::get('public_events:list_version', 1);
        Cache::forever('public_events:list_version', $version + 1);
    }

    private function eventSpecialties(int $eventId)
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('event_specialty')) return [];
        return DB::table('event_specialty as es')
            ->join('specialties as s', 's.id', '=', 'es.specialty_id')
            ->where('es.event_id', $eventId)
            ->orderBy('s.name_en')
            ->select('s.id', 's.name_en', 's.name_ar', 's.is_active')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'nameEn' => $row->name_en,
                'nameAr' => $row->name_ar,
                'isActive' => (bool) $row->is_active,
            ])->values()->all();
    }

    private function syncEventSpecialties(int $eventId, bool $targetAll, array $specialtyIds): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('event_specialty')) return;
        DB::table('event_specialty')->where('event_id', $eventId)->delete();
        if ($targetAll) return;

        $ids = DB::table('specialties')
            ->whereIn('id', array_values(array_unique(array_map('intval', $specialtyIds))))
            ->pluck('id')
            ->all();

        if (!$ids) return;
        DB::table('event_specialty')->insert(array_map(fn ($specialtyId) => [
            'event_id' => $eventId,
            'specialty_id' => $specialtyId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $ids));
    }

    private function maybeNotifyPublishedEvent($existingStatus, string $nextStatus, int $eventId): void
    {
        if ($existingStatus === 'published' || $nextStatus !== 'published') return;
        app(UserNotificationService::class)->notifyDoctorsForPublishedEvent($eventId);
    }

    private function publicEventList(Request $request)
    {
        return Cache::remember($this->publicListCacheKey($request), now()->addMinute(), function () use ($request) {
            $page = trim((string) $request->query('page', ''));
            $sortMode = trim((string) $request->query('sortMode', 'default'));
            $limit = max(1, min((int) $request->query('limit', 250), 500));

            $where = ["e.status = 'published'"];
            if ($page === 'upcoming') {
                $where[] = "((e.ends_at IS NOT NULL AND e.ends_at >= NOW()) OR (e.ends_at IS NULL AND e.starts_at >= NOW()))";
            } elseif ($page === 'previous') {
                $where[] = "((e.ends_at IS NOT NULL AND e.ends_at < NOW()) OR (e.ends_at IS NULL AND e.starts_at < NOW()))";
            }

            $orderBy = 'e.starts_at DESC';
            if ($sortMode === 'nearest') {
                $orderBy = $page === 'previous' ? 'COALESCE(e.ends_at, e.starts_at, e.created_at) DESC' : 'COALESCE(e.starts_at, e.created_at) ASC';
            } elseif ($sortMode === 'latest') {
                $orderBy = 'e.created_at DESC';
            } elseif ($sortMode === 'oldest') {
                $orderBy = 'COALESCE(e.starts_at, e.created_at) ASC';
            } elseif ($page === 'upcoming') {
                $orderBy = 'COALESCE(e.starts_at, e.created_at) ASC';
            } elseif ($page === 'previous') {
                $orderBy = 'COALESCE(e.ends_at, e.starts_at, e.created_at) DESC';
            }

            $rows = DB::select("
                SELECT
                  e.id,
                  e.slug,
                  e.title_en,
                  e.title_ar,
                  e.summary_en,
                  e.summary_ar,
                  e.type,
                  e.status,
                  e.starts_at,
                  e.ends_at,
                  e.cover_image_url,
                  e.max_attendees,
                  e.created_at,
                  e.updated_at,
                  v.name_en AS venue_name_en,
                  v.name_ar AS venue_name_ar,
                  v.city_en AS venue_city_en,
                  v.city_ar AS venue_city_ar,
                  v.capacity AS venue_capacity
                FROM events e
                LEFT JOIN venues v ON v.id = e.venue_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$orderBy}
                LIMIT ?
            ", [$limit]);

            $formatDate = function ($date) {
                return $date ? Carbon::parse($date, 'Africa/Cairo')->setTimezone('UTC')->format('Y-m-d\TH:i:s.000\Z') : null;
            };

            return array_map(function ($event) use ($formatDate) {
                return [
                    'id' => (int)$event->id,
                    'slug' => $event->slug,
                    'title_en' => $event->title_en,
                    'title_ar' => $event->title_ar,
                    'summary_en' => $event->summary_en,
                    'summary_ar' => $event->summary_ar,
                    'type' => $event->type,
                    'status' => $event->status,
                    'starts_at' => $formatDate($event->starts_at),
                    'ends_at' => $formatDate($event->ends_at),
                    'cover_image_url' => $event->cover_image_url,
                    'max_attendees' => $event->max_attendees !== null ? (int)$event->max_attendees : null,
                    'created_at' => $formatDate($event->created_at),
                    'updated_at' => $formatDate($event->updated_at),
                    'venue_name_en' => $event->venue_name_en,
                    'venue_name_ar' => $event->venue_name_ar,
                    'venue_city_en' => $event->venue_city_en,
                    'venue_city_ar' => $event->venue_city_ar,
                    'venue_capacity' => $event->venue_capacity !== null ? (int)$event->venue_capacity : null,
                ];
            }, $rows);
        });
    }

    public function index(Request $request)
    {
        $status = trim((string) $request->query('status', ''));
        $includeDeleted = filter_var($request->query('includeDeleted', 'false'), FILTER_VALIDATE_BOOLEAN);
        $page = trim((string) $request->query('page', ''));
        $sortMode = trim((string) $request->query('sortMode', 'default'));
        $limit = max(1, min((int) $request->query('limit', 250), 500));

        $allowedSorts = ['default', 'nearest', 'latest', 'oldest'];
        if ($sortMode && !in_array($sortMode, $allowedSorts)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid sortMode',
                'allowed' => $allowedSorts
            ], 400);
        }

        $allowedPages = ['upcoming', 'previous'];
        if ($page && !in_array($page, $allowedPages)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid page',
                'allowed' => $allowedPages
            ], 400);
        }

        $user = auth('api')->user();
        $canManageEvents = $user && $user->hasPermission('events.manage');

        if (!$canManageEvents) {
            return response()->json([
                'success' => true,
                'message' => 'OK',
                'data' => $this->publicEventList($request)
            ]);
        }

        $where = [];
        $bindings = [];

        if ($canManageEvents) {
            if ($status) {
                $where[] = "e.status = ?";
                $bindings[] = $status;
            }
            if (!$includeDeleted) {
                $where[] = "e.status <> 'deleted'";
            }
        } else {
            $where[] = "e.status = 'published'";
            $where[] = "e.status <> 'deleted'";
        }

        if ($page === 'upcoming') {
            $where = ["e.status = 'published'"];
            $where[] = "((e.ends_at IS NOT NULL AND e.ends_at >= NOW()) OR (e.ends_at IS NULL AND e.starts_at >= NOW()))";
        } elseif ($page === 'previous') {
            $where = ["e.status = 'published'"];
            $where[] = "((e.ends_at IS NOT NULL AND e.ends_at < NOW()) OR (e.ends_at IS NULL AND e.starts_at < NOW()))";
        }

        if ($canManageEvents) {
            $scopeClause = $this->getEventScopeClause($user, 'e');
            $where[] = "($scopeClause)";
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : 'WHERE 1=1';

        $orderBy = 'e.starts_at DESC';
        if ($sortMode === 'nearest') {
            if ($page === 'previous') $orderBy = 'COALESCE(e.ends_at, e.starts_at, e.created_at) DESC';
            else $orderBy = 'COALESCE(e.starts_at, e.created_at) ASC';
        } elseif ($sortMode === 'latest') {
            $orderBy = 'e.created_at DESC';
        } elseif ($sortMode === 'oldest') {
            $orderBy = 'COALESCE(e.starts_at, e.created_at) ASC';
        }

        $bindings[] = $limit; // for LIMIT

        $sql = $this->eventSelect() . "
            $whereClause
            GROUP BY
              e.id, e.organizer_id, e.venue_id, e.slug, e.title_en, e.title_ar, e.summary_en, e.summary_ar,
              e.description_en, e.description_ar, e.type, e.status, e.starts_at, e.ends_at,
              e.registration_starts_at, e.registration_ends_at, e.public_registration_enabled,
              e.registration_approval_mode, e.registration_access, e.max_tickets_per_checkout,
              e.capacity_hold_hours_override, e.manual_payment_enabled, e.timezone, e.cover_image_url,
              e.banner_image_url, e.event_details_image_url, e.event_pdf_url, e.gallery_json, e.google_maps_url,
              e.max_attendees, e.target_all_specialties, e.created_at, e.updated_at,
              v.name_en, v.name_ar, v.city_en, v.city_ar, v.capacity, u.name
            ORDER BY $orderBy
            LIMIT ?
        ";

        $rows = DB::select($sql, $bindings);

        $data = array_map([$this, 'normalizeEventJsonFields'], $rows);

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $data
        ]);
    }

    public function show(Request $request, $id)
    {
        $id = (int)$id;
        $user = auth('api')->user();
        if ($user && $user->hasPermission('events.manage')) {
            $scopeClause = $this->getEventScopeClause($user, 'e');
            $row = DB::selectOne($this->eventSelect() . " WHERE e.id = ? AND ($scopeClause) LIMIT 1", [$id]);
            if (!$row) return response()->json(['success' => false, 'message' => 'Event not found'], 404);
            return response()->json(['success' => true, 'message' => 'OK', 'data' => $this->normalizeEventJsonFields($row)]);
        }
        return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'slug' => 'required_without:titleEn|nullable|string|min:2',
            'titleEn' => 'required|string|min:2',
            'titleAr' => 'required_without:titleEn|nullable|string|min:2',
            'summaryEn' => 'nullable|string',
            'summaryAr' => 'nullable|string',
            'descriptionEn' => 'nullable|string',
            'descriptionAr' => 'nullable|string',
            'type' => 'nullable|in:conference,exhibition,workshop,festival,webinar,other',
            'status' => 'nullable|in:draft,published,sold_out,completed,cancelled,disabled,deleted',
            'startsAt' => 'required_without:titleEn|nullable|string|min:1',
            'endsAt' => 'required_without:titleEn|nullable|string|min:1',
            'registrationStartsAt' => 'nullable|string',
            'registrationEndsAt' => 'nullable|string',
            'publicRegistrationEnabled' => 'nullable|boolean',
            'registrationApprovalMode' => 'nullable|in:automatic,manual_review',
            'registrationAccess' => 'nullable|in:guest_allowed,login_required',
            'maxTicketsPerCheckout' => 'nullable|integer|min:1',
            'capacityHoldHoursOverride' => 'nullable|integer|min:1|max:720',
            'manualPaymentEnabled' => 'nullable|boolean',
            'timezone' => 'nullable|string',
            'maxAttendees' => 'nullable|integer|min:1',
            'coverImageUrl' => 'nullable|string',
            'bannerImageUrl' => 'nullable|string',
            'eventDetailsImageUrl' => 'nullable|string',
            'eventPdfUrl' => 'nullable|string',
            'gallery' => 'nullable|array',
            'googleMapsUrl' => 'required_without:slug|nullable|string',
            'venueId' => 'nullable|integer|min:1',
            'organizerId' => 'nullable|integer|min:1',
            'targetAllSpecialties' => 'nullable|boolean',
            'specialtyIds' => 'nullable|array',
            'specialtyIds.*' => 'integer|exists:specialties,id',
        ]);

        $titleEn = trim($validated['titleEn']);
        $titleAr = trim($validated['titleAr'] ?? '') ?: $titleEn;
        $slug = trim($validated['slug'] ?? '') ?: Str::slug($titleEn);
        if (!$slug) $slug = 'event-' . time();
        $baseSlug = $slug;
        $counter = 2;
        while (DB::table('events')->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }
        $startsAt = $validated['startsAt'] ?? now()->addDay()->format('Y-m-d H:i:s');
        $endsAt = $validated['endsAt'] ?? Carbon::parse($startsAt)->addHours(2)->format('Y-m-d H:i:s');

        $event = [
            'slug' => $slug,
            'title_en' => $titleEn,
            'title_ar' => $titleAr,
            'summary_en' => $validated['summaryEn'] ?? null,
            'summary_ar' => $validated['summaryAr'] ?? null,
            'description_en' => $validated['descriptionEn'] ?? null,
            'description_ar' => $validated['descriptionAr'] ?? null,
            'type' => $validated['type'] ?? 'conference',
            'status' => $validated['status'] ?? 'draft',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'registration_starts_at' => $validated['registrationStartsAt'] ?? null,
            'registration_ends_at' => $validated['registrationEndsAt'] ?? null,
            'public_registration_enabled' => $validated['publicRegistrationEnabled'] ?? true,
            'registration_approval_mode' => $validated['registrationApprovalMode'] ?? 'automatic',
            'registration_access' => $validated['registrationAccess'] ?? 'guest_allowed',
            'max_tickets_per_checkout' => $validated['maxTicketsPerCheckout'] ?? 1,
            'capacity_hold_hours_override' => $validated['capacityHoldHoursOverride'] ?? null,
            'manual_payment_enabled' => $validated['manualPaymentEnabled'] ?? true,
            'timezone' => $validated['timezone'] ?? 'Africa/Cairo',
            'max_attendees' => $validated['maxAttendees'] ?? null,
            'target_all_specialties' => !empty($validated['targetAllSpecialties']) ? 1 : 0,
            'cover_image_url' => $validated['coverImageUrl'] ?? null,
            'banner_image_url' => $validated['bannerImageUrl'] ?? null,
            'event_details_image_url' => $validated['eventDetailsImageUrl'] ?? null,
            'event_pdf_url' => $validated['eventPdfUrl'] ?? null,
            'gallery_json' => isset($validated['gallery']) ? json_encode($validated['gallery']) : json_encode([]),
            'google_maps_url' => $validated['googleMapsUrl'] ?? null,
            'venue_id' => $validated['venueId'] ?? null,
            'organizer_id' => $validated['organizerId'] ?? null,
        ];

        $user = auth('api')->user();
        if ($user->role_code === 'organizer') {
            $event['organizer_id'] = $user->id;
        }

        $id = DB::table('events')->insertGetId($event);
        $this->syncEventSpecialties($id, !empty($validated['targetAllSpecialties']), $validated['specialtyIds'] ?? []);
        $this->forgetPublicEventLists();
        $this->maybeNotifyPublishedEvent(null, $event['status'], $id);

        return response()->json([
            'success' => true,
            'message' => 'Event created',
            'data' => array_merge(['id' => $id], $validated)
        ]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'slug' => 'required_without:titleEn|nullable|string|min:2',
            'titleEn' => 'required|string|min:2',
            'titleAr' => 'required_without:titleEn|nullable|string|min:2',
            'summaryEn' => 'nullable|string',
            'summaryAr' => 'nullable|string',
            'descriptionEn' => 'nullable|string',
            'descriptionAr' => 'nullable|string',
            'type' => 'nullable|in:conference,exhibition,workshop,festival,webinar,other',
            'status' => 'nullable|in:draft,published,sold_out,completed,cancelled,disabled,deleted',
            'startsAt' => 'required_without:titleEn|nullable|string|min:1',
            'endsAt' => 'required_without:titleEn|nullable|string|min:1',
            'registrationStartsAt' => 'nullable|string',
            'registrationEndsAt' => 'nullable|string',
            'publicRegistrationEnabled' => 'nullable|boolean',
            'registrationApprovalMode' => 'nullable|in:automatic,manual_review',
            'registrationAccess' => 'nullable|in:guest_allowed,login_required',
            'maxTicketsPerCheckout' => 'nullable|integer|min:1',
            'capacityHoldHoursOverride' => 'nullable|integer|min:1|max:720',
            'manualPaymentEnabled' => 'nullable|boolean',
            'timezone' => 'nullable|string',
            'maxAttendees' => 'nullable|integer|min:1',
            'coverImageUrl' => 'nullable|string',
            'bannerImageUrl' => 'nullable|string',
            'eventDetailsImageUrl' => 'nullable|string',
            'eventPdfUrl' => 'nullable|string',
            'gallery' => 'nullable|array',
            'googleMapsUrl' => 'required_without:slug|nullable|string',
            'venueId' => 'nullable|integer|min:1',
            'organizerId' => 'nullable|integer|min:1',
            'targetAllSpecialties' => 'nullable|boolean',
            'specialtyIds' => 'nullable|array',
            'specialtyIds.*' => 'integer|exists:specialties,id',
        ]);

        $id = (int)$id;
        $existing = DB::table('events')->where('id', $id)->first();
        if (!$existing) return response()->json(['success' => false, 'message' => 'Event not found'], 404);

        $user = auth('api')->user();
        $roles = [$user->role->code ?? ''];
        if (!in_array('admin', $roles)) {
            if ($existing->organizer_id !== $user->id) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }
        }

        $titleEn = trim($validated['titleEn']);
        $titleAr = trim($validated['titleAr'] ?? '') ?: $titleEn;
        $slug = trim($validated['slug'] ?? '') ?: ($existing->slug ?: Str::slug($titleEn));
        $startsAt = $validated['startsAt'] ?? $existing->starts_at;
        $endsAt = $validated['endsAt'] ?? $existing->ends_at;

        $event = [
            'slug' => $slug,
            'title_en' => $titleEn,
            'title_ar' => $titleAr,
            'summary_en' => $validated['summaryEn'] ?? null,
            'summary_ar' => $validated['summaryAr'] ?? null,
            'description_en' => $validated['descriptionEn'] ?? null,
            'description_ar' => $validated['descriptionAr'] ?? null,
            'type' => $validated['type'] ?? 'conference',
            'status' => $validated['status'] ?? 'draft',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'registration_starts_at' => $validated['registrationStartsAt'] ?? null,
            'registration_ends_at' => $validated['registrationEndsAt'] ?? null,
            'public_registration_enabled' => $validated['publicRegistrationEnabled'] ?? true,
            'registration_approval_mode' => $validated['registrationApprovalMode'] ?? 'automatic',
            'registration_access' => $validated['registrationAccess'] ?? 'guest_allowed',
            'max_tickets_per_checkout' => $validated['maxTicketsPerCheckout'] ?? 1,
            'capacity_hold_hours_override' => $validated['capacityHoldHoursOverride'] ?? null,
            'manual_payment_enabled' => $validated['manualPaymentEnabled'] ?? true,
            'timezone' => $validated['timezone'] ?? 'Africa/Cairo',
            'max_attendees' => $validated['maxAttendees'] ?? null,
            'target_all_specialties' => !empty($validated['targetAllSpecialties']) ? 1 : 0,
            'cover_image_url' => $validated['coverImageUrl'] ?? null,
            'banner_image_url' => $validated['bannerImageUrl'] ?? null,
            'event_details_image_url' => $validated['eventDetailsImageUrl'] ?? null,
            'event_pdf_url' => $validated['eventPdfUrl'] ?? ($existing->event_pdf_url ?? null),
            'gallery_json' => isset($validated['gallery']) ? json_encode($validated['gallery']) : json_encode([]),
            'google_maps_url' => $validated['googleMapsUrl'] ?? null,
            'venue_id' => $validated['venueId'] ?? null,
            'organizer_id' => $validated['organizerId'] ?? null,
        ];

        if ($user->role_code === 'organizer') {
            $event['organizer_id'] = $user->id;
        }

        DB::table('events')->where('id', $id)->update($event);
        $this->syncEventSpecialties($id, !empty($validated['targetAllSpecialties']), $validated['specialtyIds'] ?? []);
        $this->forgetPublicEventLists();
        $this->maybeNotifyPublishedEvent($existing->status, $event['status'], $id);

        return response()->json([
            'success' => true,
            'message' => 'Event updated',
            'data' => array_merge(['id' => $id], $validated)
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:draft,published,sold_out,completed,cancelled,disabled,deleted'
        ]);

        $id = (int)$id;
        $existing = DB::table('events')->where('id', $id)->first();
        if (!$existing) return response()->json(['success' => false, 'message' => 'Event not found'], 404);

        $user = auth('api')->user();
        $roles = [$user->role->code ?? ''];
        if (!in_array('admin', $roles)) {
            if ($existing->organizer_id !== $user->id) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }
        }

        DB::table('events')->where('id', $id)->update(['status' => $validated['status']]);
        $this->forgetPublicEventLists();
        $this->maybeNotifyPublishedEvent($existing->status, $validated['status'], $id);

        return response()->json([
            'success' => true,
            'message' => 'Event status updated',
            'data' => ['id' => $id, 'status' => $validated['status']]
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $id = (int)$id;
        $existing = DB::table('events')->where('id', $id)->first();
        if (!$existing) return response()->json(['success' => false, 'message' => 'Event not found'], 404);

        $user = auth('api')->user();
        $roles = [$user->role->code ?? ''];
        if (!in_array('admin', $roles)) {
            if ($existing->organizer_id !== $user->id) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }
        }

        DB::table('events')->where('id', $id)->update(['status' => 'deleted']);
        $this->forgetPublicEventLists();

        return response()->json([
            'success' => true,
            'message' => 'Event moved to deleted',
            'data' => ['id' => $id, 'status' => 'deleted']
        ]);
    }

    public function restore(Request $request, $id)
    {
        $id = (int)$id;
        $existing = DB::table('events')->where('id', $id)->first();
        if (!$existing) return response()->json(['success' => false, 'message' => 'Event not found'], 404);

        $user = auth('api')->user();
        $roles = [$user->role->code ?? ''];
        if (!in_array('admin', $roles)) {
            if ($existing->organizer_id !== $user->id) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }
        }

        DB::table('events')->where('id', $id)->where('status', 'deleted')->update(['status' => 'draft']);
        $this->forgetPublicEventLists();

        return response()->json([
            'success' => true,
            'message' => 'Event restored to draft',
            'data' => ['id' => $id, 'status' => 'draft']
        ]);
    }
}
