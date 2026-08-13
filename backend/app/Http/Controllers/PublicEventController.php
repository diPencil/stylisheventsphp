<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class PublicEventController extends Controller
{
    private function eventState($event, $soldOut = false)
    {
        $now = time();
        $startsAt = $event->starts_at ? strtotime($event->starts_at) : 0;
        $endsAt = $event->ends_at ? strtotime($event->ends_at) : 0;
        $opensAt = $event->registration_starts_at ? strtotime($event->registration_starts_at) : 0;
        $closesAt = $event->registration_ends_at ? strtotime($event->registration_ends_at) : 0;

        if (intval($event->public_registration_enabled ?? 1) !== 1) return 'disabled';
        if ($event->status === 'cancelled' || $event->status === 'disabled') return 'cancelled';
        if ($event->status === 'sold_out' || $soldOut) return 'sold_out';
        if ($endsAt && $endsAt < $now) return 'ended';
        if ($opensAt && $opensAt > $now) return 'opens_soon';
        if ($closesAt && $closesAt < $now) return 'closed';
        if ($startsAt && $startsAt < $now) return 'closed';
        return 'open';
    }

    private function normalizeEventPolicy($event)
    {
        return [
            'publicRegistrationEnabled' => (bool)$event->public_registration_enabled,
            'approvalMode' => $event->registration_approval_mode,
            'access' => $event->registration_access,
            'maxTicketsPerCheckout' => (int)$event->max_tickets_per_checkout,
            'capacityHoldHoursOverride' => $event->capacity_hold_hours_override !== null ? (int)$event->capacity_hold_hours_override : null,
            'manualPaymentEnabled' => (bool)$event->manual_payment_enabled,
        ];
    }

    private function publicEventSelect()
    {
        return "
            SELECT
              e.id,
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
              e.gallery_json,
              e.google_maps_url,
              e.max_attendees,
              v.name_en AS venue_name_en,
              v.name_ar AS venue_name_ar,
              v.city_en AS venue_city_en,
              v.city_ar AS venue_city_ar,
              v.address_en AS venue_address_en,
              v.address_ar AS venue_address_ar,
              v.capacity AS venue_capacity
            FROM events e
            LEFT JOIN venues v ON v.id = e.venue_id
        ";
    }

    private function ratingSummary($eventId)
    {
        $summary = DB::selectOne("
            SELECT COUNT(*) AS review_count, COALESCE(AVG(rating), 0) AS average_rating
            FROM reviews
            WHERE event_id = ? AND status = 'approved'
        ", [$eventId]);

        $distribution = DB::select("
            SELECT rating, COUNT(*) AS total
            FROM reviews
            WHERE event_id = ? AND status = 'approved'
            GROUP BY rating
        ", [$eventId]);

        $reviews = DB::select("
            SELECT
              r.id,
              r.rating,
              r.comment,
              r.created_at,
              u.name AS customer_name,
              a.full_name AS attendee_name
            FROM reviews r
            LEFT JOIN users u ON u.id = r.customer_id
            LEFT JOIN attendees a ON a.id = r.attendee_id
            WHERE r.event_id = ? AND r.status = 'approved'
            ORDER BY r.created_at DESC, r.id DESC
            LIMIT 12
        ", [$eventId]);

        $dist = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0];
        foreach ($distribution as $row) {
            $dist[(string)$row->rating] = (int)$row->total;
        }

        $mappedReviews = array_map(function ($row) {
            $reviewerName = trim($row->attendee_name ?: $row->customer_name ?: 'Guest');
            $parts = array_values(array_filter(explode(' ', $reviewerName)));
            if (count($parts) > 1) {
                $reviewerName = $parts[0] . ' ' . (isset($parts[1][0]) ? $parts[1][0] : '') . '.';
            }

            return [
                'id' => $row->id,
                'rating' => (int)$row->rating,
                'comment' => $row->comment ?? '',
                'created_at' => $row->created_at ? Carbon::parse($row->created_at, 'Africa/Cairo')->setTimezone('UTC')->format('Y-m-d\TH:i:s.000\Z') : null,
                'reviewer_name' => $reviewerName,
            ];
        }, $reviews);

        return [
            'average' => round((float)$summary->average_rating, 1),
            'count' => (int)$summary->review_count,
            'distribution' => $dist,
            'reviews' => $mappedReviews,
        ];
    }

    public function index(Request $request)
    {
        $page = trim((string)$request->query('page', ''));
        $sortMode = trim((string)$request->query('sortMode', 'default'));
        $limit = max(1, min((int)$request->query('limit', 250), 500));

        $allowedPages = ['upcoming', 'previous'];
        if ($page && !in_array($page, $allowedPages, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid page',
                'allowed' => $allowedPages,
            ], 400);
        }

        $allowedSorts = ['default', 'nearest', 'latest', 'oldest'];
        if ($sortMode && !in_array($sortMode, $allowedSorts, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid sortMode',
                'allowed' => $allowedSorts,
            ], 400);
        }

        $where = ["e.status = 'published'"];
        if ($page === 'upcoming') {
            $where[] = "(e.ends_at IS NULL OR e.ends_at >= NOW())";
        } elseif ($page === 'previous') {
            $where[] = "e.ends_at IS NOT NULL AND e.ends_at < NOW()";
        }

        $orderBy = match ($sortMode) {
            'nearest' => 'e.starts_at ASC',
            'latest' => 'e.created_at DESC',
            'oldest' => 'e.created_at ASC',
            default => $page === 'previous' ? 'e.ends_at DESC, e.starts_at DESC' : 'e.starts_at ASC, e.created_at DESC',
        };

        $rows = DB::select("
            SELECT
              e.id,
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
              e.created_at,
              e.updated_at,
              v.name_en AS venue_name_en,
              v.name_ar AS venue_name_ar,
              v.city_en AS venue_city_en,
              v.city_ar AS venue_city_ar,
              v.capacity AS venue_capacity,
              COUNT(DISTINCT tt.id) AS ticket_types_count,
              COUNT(DISTINCT a.id) AS attendees_count,
              COUNT(DISTINCT r.id) AS registrations_count,
              COALESCE(AVG(rv.rating), 0) AS average_rating
            FROM events e
            LEFT JOIN venues v ON v.id = e.venue_id
            LEFT JOIN ticket_types tt ON tt.event_id = e.id
            LEFT JOIN attendees a ON a.event_id = e.id
            LEFT JOIN registrations r ON r.event_id = e.id
            LEFT JOIN reviews rv ON rv.event_id = e.id AND rv.status = 'approved'
            WHERE " . implode(' AND ', $where) . "
            GROUP BY e.id
            ORDER BY {$orderBy}
            LIMIT ?
        ", [$limit]);

        $formatDate = fn ($date) => $date ? Carbon::parse($date, 'Africa/Cairo')->setTimezone('UTC')->format('Y-m-d\TH:i:s.000\Z') : null;

        $events = array_map(function ($event) use ($formatDate) {
            return [
                'id' => (int)$event->id,
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
                'public_registration_enabled' => (int)$event->public_registration_enabled,
                'registration_approval_mode' => $event->registration_approval_mode,
                'registration_access' => $event->registration_access,
                'max_tickets_per_checkout' => (int)$event->max_tickets_per_checkout,
                'capacity_hold_hours_override' => $event->capacity_hold_hours_override !== null ? (int)$event->capacity_hold_hours_override : null,
                'manual_payment_enabled' => (int)$event->manual_payment_enabled,
                'timezone' => $event->timezone,
                'cover_image_url' => $event->cover_image_url,
                'banner_image_url' => $event->banner_image_url,
                'event_details_image_url' => $event->event_details_image_url,
                'event_pdf_url' => $event->event_pdf_url ?? null,
                'gallery_json' => $event->gallery_json !== null ? (string)$event->gallery_json : '[]',
                'google_maps_url' => $event->google_maps_url,
                'max_attendees' => $event->max_attendees !== null ? (int)$event->max_attendees : null,
                'created_at' => $formatDate($event->created_at),
                'updated_at' => $formatDate($event->updated_at),
                'venue_name_en' => $event->venue_name_en,
                'venue_name_ar' => $event->venue_name_ar,
                'venue_city_en' => $event->venue_city_en,
                'venue_city_ar' => $event->venue_city_ar,
                'venue_capacity' => $event->venue_capacity !== null ? (int)$event->venue_capacity : null,
                'ticket_types_count' => (int)$event->ticket_types_count,
                'attendees_count' => (int)$event->attendees_count,
                'registrations_count' => (int)$event->registrations_count,
                'average_rating' => number_format((float)$event->average_rating, 4, '.', ''),
            ];
        }, $rows);

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $events,
        ]);
    }

    public function show(Request $request, $slug)
    {
        $event = DB::selectOne(
            $this->publicEventSelect() . " WHERE e.slug = ? AND e.status = 'published' LIMIT 1",
            [$slug]
        );

        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found'], 404);
        }

        $sessions = DB::select("
            SELECT id, title_en, title_ar, speaker_name, starts_at, ends_at, room_name
            FROM event_sessions
            WHERE event_id = ?
            ORDER BY starts_at ASC
        ", [$event->id]);

        $tickets = DB::select("
            SELECT
              tt.id,
              tt.name_en,
              tt.name_ar,
              tt.description_en,
              tt.description_ar,
              tt.quota,
              tt.per_order_limit,
              COALESCE(active_regs.sold_count, 0) AS sold_count,
              tpp.id AS price_period_id,
              tpp.label_en AS price_label_en,
              tpp.label_ar AS price_label_ar,
              tpp.price,
              tpp.price_egp,
              tpp.price_usd,
              tpp.currency,
              tpp.starts_at AS price_starts_at,
              tpp.ends_at AS price_ends_at
            FROM ticket_types tt
            LEFT JOIN (
              SELECT ticket_type_id, COUNT(*) AS sold_count
              FROM registrations
              WHERE event_id = ?
                AND registration_status NOT IN ('rejected', 'cancelled', 'expired')
                AND COALESCE(capacity_reservation_status, 'active') = 'active'
              GROUP BY ticket_type_id
            ) active_regs ON active_regs.ticket_type_id = tt.id
            LEFT JOIN ticket_price_periods tpp ON tpp.id = (
              SELECT ipp.id
              FROM ticket_price_periods ipp
              WHERE ipp.ticket_type_id = tt.id
                AND ipp.is_active = 1
                AND ipp.starts_at <= NOW()
                AND ipp.ends_at >= NOW()
              ORDER BY ipp.starts_at DESC
              LIMIT 1
            )
            WHERE tt.event_id = ? AND tt.is_active = 1
            ORDER BY tt.created_at ASC
        ", [$event->id, $event->id]);

        $totals = DB::selectOne("
            SELECT COUNT(*) AS reserved_count
            FROM registrations
            WHERE event_id = ?
              AND registration_status NOT IN ('rejected', 'cancelled', 'expired')
              AND COALESCE(capacity_reservation_status, 'active') = 'active'
        ", [$event->id]);

        $reviews = $this->ratingSummary($event->id);

        $soldOut = $event->max_attendees ? (int)($totals->reserved_count ?? 0) >= (int)$event->max_attendees : false;

        $formatDate = function ($date) {
            return $date ? Carbon::parse($date, 'Africa/Cairo')->setTimezone('UTC')->format('Y-m-d\TH:i:s.000\Z') : null;
        };

        $gallery = $event->gallery_json ? json_decode($event->gallery_json) : [];
        if (!is_array($gallery)) $gallery = [];

        $eventData = [
            'id' => (int)$event->id,
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
            'public_registration_enabled' => (int)$event->public_registration_enabled,
            'registration_approval_mode' => $event->registration_approval_mode,
            'registration_access' => $event->registration_access,
            'max_tickets_per_checkout' => (int)$event->max_tickets_per_checkout,
            'capacity_hold_hours_override' => $event->capacity_hold_hours_override !== null ? (int)$event->capacity_hold_hours_override : null,
            'manual_payment_enabled' => (int)$event->manual_payment_enabled,
            'timezone' => $event->timezone,
            'cover_image_url' => $event->cover_image_url,
            'banner_image_url' => $event->banner_image_url,
            'event_details_image_url' => $event->event_details_image_url,
            'event_pdf_url' => $event->event_pdf_url ?? null,
            'gallery_json' => $event->gallery_json !== null ? (string)$event->gallery_json : "[]",
            'google_maps_url' => $event->google_maps_url,
            'max_attendees' => $event->max_attendees !== null ? (int)$event->max_attendees : null,
            'venue_name_en' => $event->venue_name_en,
            'venue_name_ar' => $event->venue_name_ar,
            'venue_city_en' => $event->venue_city_en,
            'venue_city_ar' => $event->venue_city_ar,
            'venue_address_en' => $event->venue_address_en,
            'venue_address_ar' => $event->venue_address_ar,
            'venue_capacity' => $event->venue_capacity !== null ? (int)$event->venue_capacity : null,
            'gallery' => $gallery,
            'state' => $this->eventState($event, $soldOut),
            'registration_policy' => $this->normalizeEventPolicy($event),
            'reserved_count' => (int)($totals->reserved_count ?? 0),
            'rating_summary' => [
                'average' => $reviews['average'],
                'count' => $reviews['count'],
                'distribution' => $reviews['distribution']
            ]
        ];

        $sessionsMapped = array_map(function($s) use ($formatDate) {
            return [
                'id' => (int)$s->id,
                'title_en' => $s->title_en,
                'title_ar' => $s->title_ar,
                'speaker_name' => $s->speaker_name,
                'starts_at' => $formatDate($s->starts_at),
                'ends_at' => $formatDate($s->ends_at),
                'room_name' => $s->room_name,
            ];
        }, $sessions);

        $ticketsMapped = array_map(function($t) use ($formatDate) {
            $soldCount = (int)$t->sold_count;
            $quota = $t->quota !== null ? (int)$t->quota : null;
            $remaining = $quota !== null ? max($quota - $soldCount, 0) : null;
            $isSoldOut = $quota !== null && $soldCount >= $quota;

            return [
                'id' => (int)$t->id,
                'name_en' => $t->name_en,
                'name_ar' => $t->name_ar,
                'description_en' => $t->description_en,
                'description_ar' => $t->description_ar,
                'quota' => $quota,
                'per_order_limit' => (int)$t->per_order_limit,
                'sold_count' => $soldCount,
                'price_period_id' => $t->price_period_id !== null ? (int)$t->price_period_id : null,
                'price_label_en' => $t->price_label_en,
                'price_label_ar' => $t->price_label_ar,
                'price' => $t->price !== null ? number_format((float)$t->price, 2, '.', '') : null,
                'price_egp' => $t->price_egp !== null ? number_format((float)$t->price_egp, 2, '.', '') : null,
                'price_usd' => $t->price_usd !== null ? number_format((float)$t->price_usd, 2, '.', '') : null,
                'currency' => $t->currency,
                'price_starts_at' => $formatDate($t->price_starts_at),
                'price_ends_at' => $formatDate($t->price_ends_at),
                'remaining' => $remaining,
                'is_sold_out' => $isSoldOut,
            ];
        }, $tickets);

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'event' => $eventData,
                'sessions' => $sessionsMapped,
                'reviews' => $reviews['reviews'],
                'tickets' => $ticketsMapped
            ]
        ]);
    }

    public function reviews(Request $request, $slug)
    {
        $event = DB::selectOne("SELECT id FROM events WHERE slug = ? AND status = 'published' LIMIT 1", [$slug]);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found'], 404);
        }

        $page = max(1, (int)$request->query('page', 1));
        $limit = max(1, min((int)$request->query('limit', 10), 100));
        $offset = ($page - 1) * $limit;

        $reviews = DB::select("
            SELECT
              r.id,
              r.rating,
              r.comment,
              r.created_at,
              u.name AS customer_name,
              a.full_name AS attendee_name
            FROM reviews r
            LEFT JOIN users u ON u.id = r.customer_id
            LEFT JOIN attendees a ON a.id = r.attendee_id
            WHERE r.event_id = ? AND r.status = 'approved'
            ORDER BY r.created_at DESC, r.id DESC
            LIMIT ? OFFSET ?
        ", [$event->id, $limit, $offset]);

        $total = DB::selectOne("
            SELECT COUNT(*) AS total
            FROM reviews
            WHERE event_id = ? AND status = 'approved'
        ", [$event->id]);

        $mappedReviews = array_map(function ($row) {
            $reviewerName = trim($row->attendee_name ?: $row->customer_name ?: 'Guest');
            $parts = array_values(array_filter(explode(' ', $reviewerName)));
            if (count($parts) > 1) {
                $reviewerName = $parts[0] . ' ' . (isset($parts[1][0]) ? $parts[1][0] : '') . '.';
            }

            return [
                'id' => $row->id,
                'rating' => (int)$row->rating,
                'comment' => $row->comment ?? '',
                'created_at' => $row->created_at ? Carbon::parse($row->created_at, 'Africa/Cairo')->setTimezone('UTC')->format('Y-m-d\TH:i:s.000\Z') : null,
                'reviewer_name' => $reviewerName,
            ];
        }, $reviews);

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'items' => $mappedReviews,
                'total' => (int)$total->total,
                'page' => $page,
                'limit' => $limit
            ]
        ]);
    }

    public function reviewEligibility(Request $request, $slug)
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['success' => true, 'data' => ['eligible' => false, 'state' => 'login_required', 'reason' => 'Log in to rate this event.']]);
        }

        $roleCode = $user->role_code ?? '';
        if (!in_array($roleCode, ['customer', 'doctor', 'chairman', 'speaker'])) {
            return response()->json(['success' => true, 'data' => ['eligible' => false, 'state' => 'role_denied', 'reason' => 'Only registered customers can rate this event.']]);
        }

        $event = DB::selectOne('SELECT id, title_en, title_ar, ends_at, status FROM events WHERE slug = ? LIMIT 1', [$slug]);
        if (!$event || $event->status !== 'published') {
            return response()->json(['success' => true, 'data' => ['eligible' => false, 'state' => 'event_unavailable', 'reason' => 'Event is not available for reviews.']]);
        }
        if (!$event->ends_at || strtotime($event->ends_at) > time()) {
            return response()->json(['success' => true, 'data' => ['eligible' => false, 'state' => 'event_not_ended', 'reason' => 'You can rate this event after it ends.']]);
        }

        $registration = DB::selectOne("
            SELECT
              r.id,
              r.registration_status,
              r.event_id,
              a.id AS attendee_id,
              existing.id AS review_id,
              existing.rating AS review_rating,
              existing.comment AS review_comment,
              existing.status AS review_status
            FROM registrations r
            JOIN doctors d ON d.id = r.doctor_id
            LEFT JOIN generated_tickets gt ON gt.registration_id = r.id
            LEFT JOIN attendees a ON a.id = gt.attendee_id
            LEFT JOIN reviews existing ON existing.event_id = r.event_id AND existing.customer_id = ?
            WHERE r.event_id = ?
              AND d.user_id = ?
            ORDER BY r.created_at DESC
            LIMIT 1
        ", [$user->id, $event->id, $user->id]);

        if (!$registration) {
            return response()->json(['success' => true, 'data' => ['eligible' => false, 'state' => 'not_registered', 'reason' => 'Only registered attendees can rate this event.']]);
        }
        if ($registration->registration_status !== 'approved') {
            return response()->json(['success' => true, 'data' => ['eligible' => false, 'state' => 'registration_not_eligible', 'reason' => 'Your registration is not yet eligible for rating.']]);
        }

        return response()->json(['success' => true, 'data' => [
            'eligible' => true,
            'state' => 'eligible',
            'review' => $registration->review_id ? [
                'id' => $registration->review_id,
                'rating' => (int)$registration->review_rating,
                'comment' => $registration->review_comment,
                'status' => $registration->review_status,
            ] : null,
        ]]);
    }

    public function storeReview(Request $request, $slug)
    {
        $rating = $request->input('rating');
        $comment = $request->input('comment');

        if (!is_numeric($rating) || $rating < 1 || $rating > 5) {
            return response()->json(['success' => false, 'message' => 'Validation failed'], 400);
        }

        // Fetch eligibility via the same method
        $eligibilityRes = $this->reviewEligibility($request, $slug);
        $eligibilityData = json_decode($eligibilityRes->getContent(), true)['data'];

        if (!$eligibilityData['eligible']) {
            return response()->json(['success' => false, 'message' => $eligibilityData['reason']], 403);
        }
        if (!empty($eligibilityData['review'])) {
            return response()->json(['success' => false, 'message' => 'You have already submitted a review for this event.'], 409);
        }

        $user = auth('api')->user();
        $event = DB::selectOne('SELECT id FROM events WHERE slug = ? LIMIT 1', [$slug]);

        $registration = DB::selectOne("
            SELECT r.id, a.id AS attendee_id
            FROM registrations r
            JOIN doctors d ON d.id = r.doctor_id
            LEFT JOIN generated_tickets gt ON gt.registration_id = r.id
            LEFT JOIN attendees a ON a.id = gt.attendee_id
            WHERE r.event_id = ? AND d.user_id = ?
            ORDER BY r.created_at DESC
            LIMIT 1
        ", [$event->id, $user->id]);

        $now = Carbon::now()->setTimezone('UTC')->format('Y-m-d H:i:s');

        $reviewId = DB::table('reviews')->insertGetId([
            'event_id' => $event->id,
            'customer_id' => $user->id,
            'attendee_id' => $registration->attendee_id ?? null,
            'rating' => $rating,
            'comment' => $comment ? trim($comment) : null,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $reviewId,
                'status' => 'pending'
            ],
            'message' => 'Review submitted successfully and is pending moderation.'
        ]);
    }

    public function updateReview(Request $request, $slug)
    {
        $rating = $request->input('rating');
        $comment = $request->input('comment');

        if (!is_numeric($rating) || $rating < 1 || $rating > 5) {
            return response()->json(['success' => false, 'message' => 'Validation failed'], 400);
        }

        $eligibilityRes = $this->reviewEligibility($request, $slug);
        $eligibilityData = json_decode($eligibilityRes->getContent(), true)['data'];

        if (empty($eligibilityData['review'])) {
            return response()->json(['success' => false, 'message' => 'No existing review found to update.'], 404);
        }

        $reviewId = $eligibilityData['review']['id'];

        DB::table('reviews')->where('id', $reviewId)->update([
            'rating' => $rating,
            'comment' => $comment ? trim($comment) : null,
            'status' => 'pending',
            'updated_at' => Carbon::now()->setTimezone('UTC')->format('Y-m-d H:i:s'),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $reviewId,
                'status' => 'pending'
            ],
            'message' => 'Review updated for moderation'
        ]);
    }
}
