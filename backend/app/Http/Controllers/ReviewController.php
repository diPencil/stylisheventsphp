<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class ReviewController extends Controller
{
    private function getEventScopeClause($user, $tableAlias = 'e')
    {
        if (!$user) return '1 = 0';
        $roles = [$user->role->code ?? ''];
        if (in_array('admin', $roles)) {
            return '1 = 1';
        }
        if (in_array('organizer', $roles)) {
            return "$tableAlias.organizer_id = " . (int)$user->id;
        }
        return '1 = 0';
    }

    private function requireEventScope($user, $eventId)
    {
        if (!$user) return false;
        $roles = [$user->role->code ?? ''];
        if (in_array('admin', $roles)) return true;
        if (in_array('organizer', $roles)) {
            $isOrganizer = DB::selectOne('SELECT id FROM events WHERE id = ? AND organizer_id = ?', [$eventId, $user->id]);
            return $isOrganizer ? true : false;
        }
        return false;
    }

    public function index(Request $request)
    {
        $eventId = (int)$request->query('eventId', 0);
        $limit = min(max((int) $request->query('limit', 150), 1), 1000);
        $offset = max((int) $request->query('offset', 0), 0);
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $includeMeta = filter_var($request->query('meta', false), FILTER_VALIDATE_BOOLEAN);
        $user = auth('api')->user();

        if ($eventId && !$this->requireEventScope($user, $eventId)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $scopeClause = $this->getEventScopeClause($user, 'e');

        $where = [];
        $bindings = [];

        if ($eventId !== 0) {
            $where[] = "r.event_id = ?";
            $bindings[] = $eventId;
        }
        if ($search !== '') {
            $where[] = "(r.title LIKE ? OR r.comment LIKE ? OR e.title_en LIKE ? OR e.title_ar LIKE ? OR a.full_name LIKE ? OR a.email LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
            $like = '%' . $search . '%';
            array_push($bindings, $like, $like, $like, $like, $like, $like, $like, $like);
        }
        if ($status !== '') {
            $databaseStatus = $status === 'published' ? 'approved' : $status;
            if (in_array($databaseStatus, ['approved', 'pending', 'rejected'], true)) {
                $where[] = "r.status = ?";
                $bindings[] = $databaseStatus;
            }
        }
        $where[] = "($scopeClause)";
        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $total = (int) DB::selectOne("
            SELECT COUNT(*) AS aggregate
            FROM reviews r
            JOIN events e ON e.id = r.event_id
            LEFT JOIN attendees a ON a.id = r.attendee_id
            LEFT JOIN users u ON u.id = r.customer_id
            $whereClause
        ", $bindings)->aggregate;

        $listBindings = array_merge($bindings, [$limit, $offset]);
        $rows = DB::select("
            SELECT
              r.id,
              r.rating,
              r.title,
              r.comment,
              r.status,
              r.created_at,
              e.title_en AS event_title_en,
              e.title_ar AS event_title_ar,
              a.full_name AS attendee_name,
              a.email AS attendee_email,
              u.name AS customer_name,
              u.email AS customer_email
            FROM reviews r
            JOIN events e ON e.id = r.event_id
            LEFT JOIN attendees a ON a.id = r.attendee_id
            LEFT JOIN users u ON u.id = r.customer_id
            $whereClause
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ", $listBindings);

        $formatDate = function ($date) {
            return $date ? Carbon::parse($date, 'Africa/Cairo')->setTimezone('UTC')->format('Y-m-d\TH:i:s.000\Z') : null;
        };

        $mapped = array_map(function($r) use ($formatDate) {
            return [
                'id' => (int)$r->id,
                'rating' => (int)$r->rating,
                'title' => $r->title,
                'comment' => $r->comment,
                'status' => $r->status,
                'created_at' => $formatDate($r->created_at),
                'event_title_en' => $r->event_title_en,
                'event_title_ar' => $r->event_title_ar,
                'attendee_name' => $r->attendee_name,
                'attendee_email' => $r->attendee_email,
                'customer_name' => $r->customer_name,
                'customer_email' => $r->customer_email,
            ];
        }, $rows);

        $response = [
            'success' => true,
            'message' => 'OK',
            'data' => $mapped
        ];

        if ($includeMeta) {
            $response['pagination'] = [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ];
        }

        return response()->json($response);
    }

    public function show(Request $request, $id)
    {
        $row = DB::selectOne("
            SELECT
              r.id,
              r.rating,
              r.title,
              r.comment,
              r.status,
              r.created_at,
              e.id AS event_id,
              e.title_en AS event_title_en,
              e.title_ar AS event_title_ar,
              e.slug AS event_slug,
              e.starts_at AS event_starts_at,
              e.ends_at AS event_ends_at,
              a.id AS attendee_id,
              a.full_name AS attendee_name,
              a.email AS attendee_email,
              a.phone AS attendee_phone,
              a.checked_in_at,
              u.id AS customer_id,
              u.name AS customer_name,
              u.email AS customer_email,
              u.phone AS customer_phone,
              u.country_name,
              u.country_code,
              u.gender
            FROM reviews r
            JOIN events e ON e.id = r.event_id
            LEFT JOIN attendees a ON a.id = r.attendee_id
            LEFT JOIN users u ON u.id = r.customer_id
            WHERE r.id = ?
            LIMIT 1
        ", [$id]);

        if (!$row) {
            return response()->json(['success' => false, 'message' => 'Review not found'], 404);
        }

        $user = auth('api')->user();
        if (!$this->requireEventScope($user, $row->event_id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $formatDate = function ($date) {
            return $date ? Carbon::parse($date, 'Africa/Cairo')->setTimezone('UTC')->format('Y-m-d\TH:i:s.000\Z') : null;
        };

        $data = [
            'id' => (int)$row->id,
            'rating' => (int)$row->rating,
            'title' => $row->title,
            'comment' => $row->comment,
            'status' => $row->status,
            'created_at' => $formatDate($row->created_at),
            'event_id' => (int)$row->event_id,
            'event_title_en' => $row->event_title_en,
            'event_title_ar' => $row->event_title_ar,
            'event_slug' => $row->event_slug,
            'event_starts_at' => $formatDate($row->event_starts_at),
            'event_ends_at' => $formatDate($row->event_ends_at),
            'attendee_id' => $row->attendee_id !== null ? (int)$row->attendee_id : null,
            'attendee_name' => $row->attendee_name,
            'attendee_email' => $row->attendee_email,
            'attendee_phone' => $row->attendee_phone,
            'checked_in_at' => $formatDate($row->checked_in_at),
            'customer_id' => $row->customer_id !== null ? (int)$row->customer_id : null,
            'customer_name' => $row->customer_name,
            'customer_email' => $row->customer_email,
            'customer_phone' => $row->customer_phone,
            'country_name' => $row->country_name,
            'country_code' => $row->country_code,
            'gender' => $row->gender,
        ];

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $data
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:approved,rejected,pending'
        ]);

        $id = (int)$id;
        $existing = DB::table('reviews')->where('id', $id)->first();
        if (!$existing) return response()->json(['success' => false, 'message' => 'Review not found'], 404);

        $user = auth('api')->user();
        if (!$this->requireEventScope($user, $existing->event_id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        DB::table('reviews')->where('id', $id)->update([
            'status' => $validated['status'],
            'updated_at' => Carbon::now()->setTimezone('UTC')->format('Y-m-d H:i:s')
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Review status updated',
            'data' => ['id' => $id, 'status' => $validated['status']]
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $id = (int)$id;
        $existing = DB::table('reviews')->where('id', $id)->first();
        if (!$existing) return response()->json(['success' => false, 'message' => 'Review not found'], 404);

        $user = auth('api')->user();
        if (!$this->requireEventScope($user, $existing->event_id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        DB::table('reviews')->where('id', $id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Review deleted',
            'data' => ['id' => $id]
        ]);
    }
}
